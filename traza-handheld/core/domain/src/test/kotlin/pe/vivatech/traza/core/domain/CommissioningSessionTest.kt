package pe.vivatech.traza.core.domain

import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.test.runTest
import pe.vivatech.traza.core.reader.FakeRfidReader
import pe.vivatech.traza.core.reader.ReadProfile
import pe.vivatech.traza.core.reader.ReaderEvent
import pe.vivatech.traza.core.reader.RfidReader
import pe.vivatech.traza.core.reader.TagRead
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.emptyFlow
import kotlinx.coroutines.flow.flowOf
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertIs
import kotlin.test.assertNull
import kotlin.test.assertTrue

/** Tarea 5.4: modo tarado. */
class CommissioningSessionTest {

    private class MemoryStore : CommissionedTagStore {
        val tags = LinkedHashMap<String, Long>()

        override fun variantFor(epc: String): Long? = tags[epc]

        override fun save(
            epc: String,
            tid: String?,
            productVariantId: Long,
            locationId: Long,
            zoneId: Long?,
            commissionedAt: Long,
        ) {
            tags[epc] = productVariantId
        }
    }

    /** Lector que entrega los EPC que le digas, en orden. */
    private class ScriptedReader(private val epcs: MutableList<String>) : RfidReader {
        var appliedProfile: ReadProfile? = null

        override val events: Flow<ReaderEvent> = emptyFlow()
        override val batteryLevel = MutableStateFlow(90)
        override val isConnected = MutableStateFlow(true)

        override suspend fun connect() = Result.success(Unit)
        override suspend fun disconnect() {}

        override suspend fun applyProfile(profile: ReadProfile): Result<Unit> {
            appliedProfile = profile
            return Result.success(Unit)
        }

        override suspend fun startInventory() = Result.success(Unit)
        override suspend fun stopInventory() {}

        override suspend fun readSingle(timeoutMs: Long): Result<TagRead> {
            val epc = epcs.removeFirstOrNull()
                ?: return Result.failure(IllegalStateException("Sin tag"))

            return Result.success(
                TagRead(epc, "E280$epc", -42, 1, 1_700_000_000_000, 3),
            )
        }

        override suspend fun writeEpc(currentEpc: String, newEpc: String, accessPassword: String?) =
            Result.success(Unit)

        override fun locate(epc: String): Flow<Int> = flowOf(0)
    }

    private fun session(
        epcs: List<String>,
        store: CommissionedTagStore = MemoryStore(),
        variantId: Long = 100,
    ): Pair<CommissioningSession, ScriptedReader> {
        val reader = ScriptedReader(epcs.toMutableList())
        return CommissioningSession(
            reader = reader,
            store = store,
            activeVariantId = variantId,
            locationId = 1,
            now = { 1_700_000_000_000 },
        ) to reader
    }

    @Test
    fun `entrar en el modo aplica el perfil de potencia baja`() = runTest {
        /*
         * Es el fallo clásico del tarado: con potencia alta se lee la caja
         * entera de la trastienda y el SKU se asocia al tag equivocado. Ese
         * error es prácticamente indetectable después.
         */
        val (session, reader) = session(emptyList())

        session.start()

        assertEquals(ReadProfile.TARADO, reader.appliedProfile)
        assertTrue(reader.appliedProfile!!.txPowerDbm <= 18)
    }

    @Test
    fun `tarar una prenda nueva la guarda y suma al contador`() = runTest {
        val store = MemoryStore()
        val (session, _) = session(listOf("3035D9AA"), store)

        val result = session.commissionOne()

        assertIs<CommissionResult.Ok>(result)
        assertEquals(100L, store.tags["3035D9AA"])
        assertEquals(1, session.state.commissionedInSession)
        assertEquals("3035D9AA", session.state.lastEpc)
    }

    @Test
    fun `un epc ya tarado al mismo sku no vuelve a guardarse`() = runTest {
        val store = MemoryStore().apply { tags["3035D9AA"] = 100 }
        val (session, _) = session(listOf("3035D9AA"), store)

        val result = session.commissionOne()

        assertIs<CommissionResult.AlreadyCommissionedSameSku>(result)
        assertEquals(0, session.state.commissionedInSession)
    }

    @Test
    fun `un epc de otro producto da conflicto y no se sobrescribe solo`() = runTest {
        // Sobrescribir en silencio descuadra el inventario de las dos
        // variantes y nadie sabe después de dónde salió.
        val store = MemoryStore().apply { tags["3035D9AA"] = 999 }
        val (session, _) = session(listOf("3035D9AA"), store)

        val result = session.commissionOne()

        val conflict = assertIs<CommissionResult.Conflict>(result)
        assertEquals(999L, conflict.existingVariantId)
        assertEquals(999L, store.tags["3035D9AA"], "El tag no debe reasignarse sin confirmar.")
        assertTrue(result.requiresConfirmation())
    }

    @Test
    fun `el conflicto se reasigna solo tras confirmacion explicita`() = runTest {
        val store = MemoryStore().apply { tags["3035D9AA"] = 999 }
        val (session, _) = session(listOf("3035D9AA"), store)

        session.commissionOne()
        val result = session.confirmReassignment()

        assertIs<CommissionResult.Ok>(result)
        assertEquals(100L, store.tags["3035D9AA"])
        assertNull(session.state.pendingConflict)
    }

    @Test
    fun `descartar el conflicto deja el tag como estaba`() = runTest {
        val store = MemoryStore().apply { tags["3035D9AA"] = 999 }
        val (session, _) = session(listOf("3035D9AA"), store)

        session.commissionOne()
        session.dismissConflict()

        assertEquals(999L, store.tags["3035D9AA"])
        assertEquals(0, session.state.commissionedInSession)
        assertNull(session.state.pendingConflict)
    }

    @Test
    fun `no leer nada no es un error del operario`() = runTest {
        val (session, _) = session(emptyList())

        val result = session.commissionOne()

        assertEquals(CommissionResult.NoTag, result)
        // Silencio: castigar con un pitido cada vez que el gatillo no engancha
        // vuelve insufrible tarar trescientas prendas.
        assertEquals(FeedbackSignal.SILENT, result.signal())
        assertFalse(result.requiresConfirmation())
    }

    @Test
    fun `cada resultado suena distinto`() {
        // El operario aprende a distinguirlos sin mirar la pantalla, así que
        // dos resultados con la misma señal serían un error de diseño.
        val signals = listOf(
            CommissionResult.Ok("A", null),
            CommissionResult.AlreadyCommissionedSameSku("A"),
            CommissionResult.Conflict("A", 1),
            CommissionResult.NoTag,
        ).map { it.signal() }

        assertEquals(signals.size, signals.toSet().size, "Hay dos resultados con la misma señal.")
        assertEquals(FeedbackSignal.LONG_LOW_WITH_VIBRATION, CommissionResult.Conflict("A", 1).signal())
    }

    @Test
    fun `cambiar de sku conserva el contador del turno`() = runTest {
        val (session, _) = session(listOf("3035D9AA"))
        session.commissionOne()

        session.changeVariant(200)

        assertEquals(200L, session.state.activeVariantId)
        assertEquals(1, session.state.commissionedInSession)
    }

    @Test
    fun `funciona contra el lector falso sin hardware`() = runTest {
        val reader = FakeRfidReader(scope = CoroutineScope(coroutineContext), populationSize = 5, seed = 3)
        reader.connect()

        val session = CommissioningSession(reader, MemoryStore(), activeVariantId = 1, locationId = 1)
        session.start()

        assertIs<CommissionResult.Ok>(session.commissionOne())
    }
}
