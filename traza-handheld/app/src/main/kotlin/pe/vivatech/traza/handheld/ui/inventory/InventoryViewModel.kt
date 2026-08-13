package pe.vivatech.traza.handheld.ui.inventory

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.filterIsInstance
import kotlinx.coroutines.launch
import pe.vivatech.traza.core.domain.InventorySession
import pe.vivatech.traza.core.domain.InventoryState
import pe.vivatech.traza.core.domain.PendingScan
import pe.vivatech.traza.core.reader.ReadProfile
import pe.vivatech.traza.core.reader.ReaderEvent
import pe.vivatech.traza.core.reader.RfidReader
import pe.vivatech.traza.handheld.data.local.ScanDao
import pe.vivatech.traza.handheld.data.local.ScanEntity
import pe.vivatech.traza.handheld.data.sync.SyncScheduler
import javax.inject.Inject

/**
 * Modo inventario. Ver `docs/09` §5.
 *
 * El ViewModel no decide nada: la deduplicación vive en `InventorySession`,
 * en `core:domain`, donde se puede medir con 20 000 EPC en una prueba de
 * JUnit corriente. Aquí solo se conecta el lector, se escribe en Room y se
 * expone el estado.
 */
@HiltViewModel
class InventoryViewModel @Inject constructor(
    private val reader: RfidReader,
    private val scanDao: ScanDao,
    private val syncScheduler: SyncScheduler,
) : ViewModel() {

    private var session = InventorySession(cycleId = 0)

    private val _state = MutableStateFlow(InventoryState())
    val state: StateFlow<InventoryState> = _state.asStateFlow()

    init {
        viewModelScope.launch {
            reader.events
                .filterIsInstance<ReaderEvent.TagsRead>()
                .collect { handleReads(it) }
        }

        viewModelScope.launch {
            reader.events.filterIsInstance<ReaderEvent.TriggerPressed>().collect { startBurst() }
        }

        viewModelScope.launch {
            reader.events.filterIsInstance<ReaderEvent.TriggerReleased>().collect { endBurst() }
        }
    }

    /**
     * Arranca o reanuda un ciclo.
     *
     * Se restauran los EPC ya leídos desde Room: si la aplicación se cerró
     * por memoria a mitad de barrido —cosa que pasa en equipos de 2 GB— sin
     * esto se volvería a contar desde cero todo lo ya recorrido.
     */
    fun open(cycleId: Long, expectedCount: Int, zoneId: Long? = null) {
        session = InventorySession(cycleId, expectedCount, zoneId)

        viewModelScope.launch {
            session.restore(scanDao.epcsForCycle(cycleId))
            session.updatePending(scanDao.pendingCount())
            _state.value = session.state

            reader.connect()
            reader.applyProfile(ReadProfile.INVENTARIO)
            reader.startInventory()
        }
    }

    fun startBurst() {
        session.startBurst()
        _state.value = session.state
    }

    fun endBurst() {
        session.endBurst()
        _state.value = session.state
    }

    fun changeZone(zoneId: Long?) {
        session.changeZone(zoneId)
        _state.value = session.state
    }

    fun togglePause() {
        if (session.state.isPaused) {
            session.resume()
            viewModelScope.launch { reader.startInventory() }
        } else {
            session.pause()
            viewModelScope.launch { reader.stopInventory() }
        }
        _state.value = session.state
    }

    private suspend fun handleReads(event: ReaderEvent.TagsRead) {
        val fresh = session.accept(event.reads)
        if (fresh.isEmpty()) return

        // Escritura local primero. Siempre. La red viene después, y puede no
        // venir en toda la mañana.
        scanDao.insertAll(fresh.map { it.toEntity() })
        session.updatePending(scanDao.pendingCount())

        _state.value = session.state

        syncScheduler.requestSync()
    }

    private fun PendingScan.toEntity() = ScanEntity(
        epc = epc,
        tid = tid,
        rssi = rssi,
        seenAt = seenAt,
        cycleId = cycleId,
        zoneId = zoneId,
    )

    override fun onCleared() {
        super.onCleared()
        viewModelScope.launch { reader.stopInventory() }
    }
}
