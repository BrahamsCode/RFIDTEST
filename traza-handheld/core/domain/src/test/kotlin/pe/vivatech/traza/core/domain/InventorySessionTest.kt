package pe.vivatech.traza.core.domain

import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.test.runTest
import pe.vivatech.traza.core.reader.FakeRfidReader
import pe.vivatech.traza.core.reader.TagRead
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertTrue
import kotlin.time.measureTime

/** Tarea 5.2: modo inventario. */
class InventorySessionTest {

    private fun read(epc: String, at: Long = 1_700_000_000_000) = TagRead(
        epc = epc, tid = null, rssi = -50, antenna = 1, seenAt = at, readCount = 1,
    )

    @Test
    fun `un epc repetido solo cuenta una vez`() {
        val session = InventorySession(cycleId = 1)

        val first = session.accept(listOf(read("3035D9AA"), read("3035D9BB")))
        val second = session.accept(listOf(read("3035D9AA"), read("3035D9CC")))

        assertEquals(2, first.size)
        assertEquals(listOf("3035D9CC"), second.map { it.epc })
        assertEquals(3, session.state.scannedCount)
    }

    @Test
    fun `veinte mil epc se deduplican sin degradar la fluidez`() {
        /*
         * Criterio de aceptación de la tarea 5.2. El número que importa no es
         * el total sino el peor lote: si un lote tarda más de un fotograma
         * (16 ms a 60 Hz), la pantalla se atasca mientras alguien barre, y
         * eso se nota en la mano.
         */
        val session = InventorySession(cycleId = 1, expectedCount = 20_000)
        val population = (0 until 20_000).map { "3035D9%018X".format(it) }

        var worstBatchMs = 0.0

        val total = measureTime {
            // Lotes de 25 como los del lector, y una segunda vuelta entera
            // para que la mitad de las lecturas sean repetidas: es lo que
            // pasa de verdad al barrer una tienda dos veces.
            for (pass in 1..2) {
                population.chunked(25).forEach { chunk ->
                    val batch = measureTime { session.accept(chunk.map { read(it) }) }
                    val ms = batch.inWholeMicroseconds / 1000.0
                    if (ms > worstBatchMs) worstBatchMs = ms
                }
            }
        }

        assertEquals(20_000, session.state.scannedCount)
        assertTrue(worstBatchMs < 16.0, "El peor lote tardó $worstBatchMs ms (límite 16 ms).")
        assertTrue(total.inWholeMilliseconds < 2_000, "40 000 lecturas tardaron $total.")
    }

    @Test
    fun `la pasada se reinicia al apretar el gatillo`() {
        val session = InventorySession(cycleId = 1)

        session.startBurst()
        session.accept(listOf(read("3035D9AA"), read("3035D9BB")))
        assertEquals(2, session.state.newTagsInBurst)

        session.endBurst()
        // Al soltar se mantiene: es lo que la persona acaba de leer y quiere ver.
        assertEquals(2, session.state.newTagsInBurst)

        session.startBurst()
        assertEquals(0, session.state.newTagsInBurst)
        assertEquals(2, session.state.scannedCount)
    }

    @Test
    fun `en pausa no se acepta nada`() {
        val session = InventorySession(cycleId = 1)
        session.pause()

        assertTrue(session.accept(listOf(read("3035D9AA"))).isEmpty())
        assertEquals(0, session.state.scannedCount)

        session.resume()
        assertEquals(1, session.accept(listOf(read("3035D9AA"))).size)
    }

    @Test
    fun `cambiar de zona no vuelve a contar lo ya leido`() {
        // Si al cambiar de estantería se reiniciara la deduplicación, el
        // ciclo saldría inflado con existencias que no están.
        val session = InventorySession(cycleId = 1, zoneId = 10)
        session.accept(listOf(read("3035D9AA")))

        session.changeZone(20)
        val again = session.accept(listOf(read("3035D9AA"), read("3035D9BB")))

        assertEquals(listOf("3035D9BB"), again.map { it.epc })
        assertEquals(20L, again.single().zoneId)
        assertEquals(2, session.state.scannedCount)
    }

    @Test
    fun `el escaneo pendiente lleva el ciclo y la zona activos`() {
        val session = InventorySession(cycleId = 77, zoneId = 5)

        val pending = session.accept(listOf(read("3035D9AA", at = 123))).single()

        assertEquals(77L, pending.cycleId)
        assertEquals(5L, pending.zoneId)
        assertEquals(123L, pending.seenAt)
    }

    @Test
    fun `al reabrir la aplicacion se reanuda donde estaba`() {
        // `docs/09` §9: el estado vive en Room. Sin restaurar, un cierre por
        // memoria a mitad de barrido volvería a contar lo ya leído.
        val session = InventorySession(cycleId = 1)
        session.restore(listOf("3035D9AA", "3035D9BB", "3035D9CC"))

        assertEquals(3, session.state.scannedCount)
        assertTrue(session.contains("3035D9AA"))
        assertTrue(session.accept(listOf(read("3035D9AA"))).isEmpty())
    }

    @Test
    fun `el progreso no divide entre cero sin conteo esperado`() {
        val session = InventorySession(cycleId = 1, expectedCount = 0)
        session.accept(listOf(read("3035D9AA")))

        assertEquals(0.0, session.state.progressPct)
    }

    @Test
    fun `consume los lotes del lector falso sin hardware`() = runTest {
        val reader = FakeRfidReader(scope = CoroutineScope(coroutineContext), populationSize = 40, seed = 7)
        reader.connect()

        val session = InventorySession(cycleId = 1, expectedCount = 40)
        repeat(60) { session.accept(reader.burst()) }

        assertTrue(session.state.scannedCount in 1..40)
        assertFalse(session.state.isPaused)
    }
}
