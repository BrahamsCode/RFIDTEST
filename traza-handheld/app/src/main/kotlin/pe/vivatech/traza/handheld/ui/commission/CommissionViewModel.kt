package pe.vivatech.traza.handheld.ui.commission

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import pe.vivatech.traza.core.domain.CommissionResult
import pe.vivatech.traza.core.domain.CommissionedTagStore
import pe.vivatech.traza.core.domain.CommissioningSession
import pe.vivatech.traza.core.domain.CommissioningState
import pe.vivatech.traza.core.domain.FeedbackSignal
import pe.vivatech.traza.core.domain.signal
import pe.vivatech.traza.core.reader.RfidReader
import pe.vivatech.traza.handheld.data.local.TagDao
import pe.vivatech.traza.handheld.data.local.TagEntity
import pe.vivatech.traza.handheld.data.remote.DeviceCredentials
import pe.vivatech.traza.handheld.data.sync.SyncScheduler
import javax.inject.Inject

/** Modo tarado. Ver `docs/09` §8. */
@HiltViewModel
class CommissionViewModel @Inject constructor(
    private val reader: RfidReader,
    private val tagDao: TagDao,
    private val credentials: DeviceCredentials,
    private val syncScheduler: SyncScheduler,
) : ViewModel() {

    private var session: CommissioningSession? = null

    private val _state = MutableStateFlow(CommissioningState(activeVariantId = 0))
    val state: StateFlow<CommissioningState> = _state.asStateFlow()

    /** Señal que debe emitir la pantalla. Se consume una vez y se limpia. */
    private val _signal = MutableStateFlow<FeedbackSignal?>(null)
    val signal: StateFlow<FeedbackSignal?> = _signal.asStateFlow()

    fun open(variantId: Long) {
        val store = RoomStore()

        session = CommissioningSession(
            reader = reader,
            store = store,
            activeVariantId = variantId,
            locationId = credentials.locationId,
        )

        viewModelScope.launch {
            reader.connect()
            session?.start()
            _state.value = session?.state ?: return@launch
        }
    }

    /** Un tirón de gatillo, una prenda. Sin diálogos salvo el del conflicto. */
    fun commissionOne() {
        val current = session ?: return

        viewModelScope.launch {
            val result = current.commissionOne()

            _state.value = current.state
            _signal.value = result.signal()

            if (result is CommissionResult.Ok) syncScheduler.requestSync()
        }
    }

    fun confirmReassignment() {
        val current = session ?: return

        val result = current.confirmReassignment()
        _state.value = current.state
        _signal.value = result.signal()

        syncScheduler.requestSync()
    }

    fun dismissConflict() {
        session?.dismissConflict()
        _state.value = session?.state ?: return
    }

    fun changeVariant(variantId: Long) {
        session?.changeVariant(variantId)
        _state.value = session?.state ?: return
    }

    fun consumeSignal() {
        _signal.value = null
    }

    /**
     * Room con acceso síncrono.
     *
     * `CommissioningSession` es lógica pura y no sabe de corrutinas; aquí se
     * traduce con `runBlocking` sobre una consulta indexada de una fila, que
     * son microsegundos. Bloquear el hilo del gatillo con algo más pesado
     * sería otra historia.
     */
    private inner class RoomStore : CommissionedTagStore {

        override fun variantFor(epc: String): Long? = kotlinx.coroutines.runBlocking {
            tagDao.findByEpc(epc)?.productVariantId
        }

        override fun save(
            epc: String,
            tid: String?,
            productVariantId: Long,
            locationId: Long,
            zoneId: Long?,
            commissionedAt: Long,
        ) {
            kotlinx.coroutines.runBlocking {
                tagDao.insert(
                    TagEntity(
                        epc = epc,
                        tid = tid,
                        productVariantId = productVariantId,
                        locationId = locationId,
                        zoneId = zoneId,
                        commissionedAt = commissionedAt,
                        pendingSync = true,
                    ),
                )
            }
        }
    }
}
