package pe.vivatech.traza.core.domain

import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertIs
import kotlin.test.assertTrue

/** Tarea 5.6: alta por QR, lado del dispositivo. */
class EnrollmentTest {

    private fun validate(
        version: Int? = 1,
        url: String? = "https://traza.ejemplo.pe",
        deviceCode: String? = "HH-LIM01-02",
        token: String? = "eyJ0eXAiOi",
        locationId: Long? = 1,
    ) = Enrollment.validate(version, url, deviceCode, token, locationId)

    @Test
    fun `un qr correcto se acepta`() {
        val ok = assertIs<EnrollmentParse.Ok>(validate())

        assertEquals("HH-LIM01-02", ok.payload.deviceCode)
        assertEquals(1L, ok.payload.locationId)
    }

    @Test
    fun `la barra final de la url se quita`() {
        // Si no, cada petición saldría con doble barra y algún proxy la
        // rechazaría por una razón que nadie encontraría en tienda.
        val ok = assertIs<EnrollmentParse.Ok>(validate(url = "https://traza.ejemplo.pe/"))

        assertEquals("https://traza.ejemplo.pe", ok.payload.url)
    }

    @Test
    fun `http contra un servidor remoto se rechaza`() {
        /*
         * El token permanente del dispositivo viaja en la respuesta del
         * canje. Aceptar http es entregárselo a cualquiera que esté en el
         * wifi de la galería.
         */
        val invalid = assertIs<EnrollmentParse.Invalid>(validate(url = "http://traza.ejemplo.pe"))

        assertTrue(invalid.reason.contains("https"))
    }

    @Test
    fun `http contra localhost se admite para desarrollo`() {
        assertIs<EnrollmentParse.Ok>(validate(url = "http://localhost:8000"))
        assertIs<EnrollmentParse.Ok>(validate(url = "http://127.0.0.1:8000"))
    }

    @Test
    fun `un qr de otra version dice que hay que actualizar`() {
        val invalid = assertIs<EnrollmentParse.Invalid>(validate(version = 2))

        assertTrue(invalid.reason.contains("Actualiza"))
    }

    @Test
    fun `faltar cualquier campo se explica en castellano`() {
        assertIs<EnrollmentParse.Invalid>(validate(url = null))
        assertIs<EnrollmentParse.Invalid>(validate(deviceCode = " "))
        assertIs<EnrollmentParse.Invalid>(validate(token = null))
        assertIs<EnrollmentParse.Invalid>(validate(locationId = 0))
        assertIs<EnrollmentParse.Invalid>(validate(version = null))
    }
}
