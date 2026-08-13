package pe.vivatech.traza.handheld

import pe.vivatech.traza.core.domain.InventorySession
import pe.vivatech.traza.core.reader.TagRead
import pe.vivatech.traza.handheld.data.local.ScanEntity
import java.time.Instant
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertTrue

/**
 * Lo que se prueba aquí es la traducción entre capas. La lógica está en
 * `core:domain` y ya tiene sus pruebas; lo que se rompe sin ruido es el
 * mapeo, porque compila igual.
 */
class ScanEntityMappingTest {

    @Test
    fun `un escaneo nace pendiente`() {
        // Si naciera con otro estado, el worker no lo recogería nunca y la
        // cola crecería sin que nadie viera un error.
        val scan = ScanEntity(epc = "3035D9AA", tid = null, rssi = -50, seenAt = 1, cycleId = 1, zoneId = null)

        assertEquals(ScanEntity.STATUS_PENDING, scan.status)
    }

    @Test
    fun `la marca de tiempo del lector sobrevive al viaje hasta el dto`() {
        /*
         * El servidor usa `read_at` para decidir a qué partición va la
         * lectura. Si aquí se pusiera la hora de la subida en vez de la de
         * la lectura, un ciclo hecho sin cobertura por la mañana aparecería
         * fechado a la tarde, cuando por fin hubo wifi.
         */
        val session = InventorySession(cycleId = 9)
        val leidoA = 1_700_000_123_456L

        val pending = session.accept(
            listOf(TagRead("3035D9AA", null, -50, 1, leidoA, 1)),
        ).single()

        val entity = ScanEntity(
            epc = pending.epc,
            tid = pending.tid,
            rssi = pending.rssi,
            seenAt = pending.seenAt,
            cycleId = pending.cycleId,
            zoneId = pending.zoneId,
        )

        assertEquals(leidoA, entity.seenAt)
        assertEquals("2023-11-14T22:15:23.456Z", Instant.ofEpochMilli(entity.seenAt).toString())
    }

    @Test
    fun `el lote del worker no supera el maximo del servidor`() {
        // `TRAZA_MAX_BATCH` en el API vale 1000. Un lote mayor se rechazaría
        // con un 422, que la política marca como fallido y no reintenta:
        // se perderían escaneos buenos.
        assertTrue(
            pe.vivatech.traza.handheld.data.sync.UploadScansWorker.BATCH_SIZE <= 1000,
            "El lote del handheld supera el máximo que acepta la ingesta.",
        )
    }
}
