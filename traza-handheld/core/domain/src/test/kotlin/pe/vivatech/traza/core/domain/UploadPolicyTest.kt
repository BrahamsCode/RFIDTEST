package pe.vivatech.traza.core.domain

import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertIs
import kotlin.test.assertTrue

/** Tarea 5.3: qué hacer con un lote según cómo falle. */
class UploadPolicyTest {

    @Test
    fun `sin red se reintenta`() {
        val decision = UploadPolicy.decide(UploadAttempt.Network("no route to host"))

        assertIs<UploadDecision.Retry>(decision)
    }

    @Test
    fun `un 5xx se reintenta`() {
        listOf(500, 502, 503, 504).forEach { code ->
            assertIs<UploadDecision.Retry>(
                UploadPolicy.decide(UploadAttempt.Http(code)),
                "El $code debería reintentarse.",
            )
        }
    }

    @Test
    fun `un 401 o un 403 avisan del problema de credenciales`() {
        // No es culpa del lote: hasta que alguien vuelva a dar de alta el
        // equipo no va a subir nada, y reintentar en bucle solo consume
        // batería sin arreglarlo.
        listOf(401, 403).forEach { code ->
            assertIs<UploadDecision.AuthProblem>(UploadPolicy.decide(UploadAttempt.Http(code)))
        }
    }

    @Test
    fun `un 4xx del cliente se marca fallido y no se reintenta`() {
        val decision = UploadPolicy.decide(UploadAttempt.Http(422, "epc inválido"))

        val failed = assertIs<UploadDecision.MarkFailed>(decision)
        assertTrue(failed.reason.contains("422"))
        assertTrue(failed.reason.contains("epc inválido"))
    }

    @Test
    fun `el 429 se reintenta aunque sea 4xx`() {
        // Es el servidor pidiendo explícitamente que se vuelva más tarde.
        // Tratarlo como lote inválido tiraría escaneos buenos.
        assertIs<UploadDecision.Retry>(UploadPolicy.decide(UploadAttempt.Http(429)))
        assertIs<UploadDecision.Retry>(UploadPolicy.decide(UploadAttempt.Http(408)))
    }

    @Test
    fun `un 2xx marca el lote subido`() {
        assertEquals(UploadDecision.MarkUploaded, UploadPolicy.decide(UploadAttempt.Success))
        assertEquals(UploadDecision.MarkUploaded, UploadPolicy.decide(UploadAttempt.Http(202)))
    }

    @Test
    fun `el retroceso crece exponencialmente y tiene techo`() {
        assertEquals(15, UploadPolicy.backoffSeconds(1))
        assertEquals(30, UploadPolicy.backoffSeconds(2))
        assertEquals(60, UploadPolicy.backoffSeconds(3))
        assertEquals(480, UploadPolicy.backoffSeconds(6))

        // Sin techo, una mañana sin cobertura en la trastienda dejaría el
        // siguiente intento a más de una hora, con el ciclo ya terminado.
        assertEquals(UploadPolicy.MAX_BACKOFF_SECONDS, UploadPolicy.backoffSeconds(8))
        assertEquals(UploadPolicy.MAX_BACKOFF_SECONDS, UploadPolicy.backoffSeconds(60))
    }
}
