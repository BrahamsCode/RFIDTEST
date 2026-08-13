package pe.vivatech.traza.handheld.ui.locate

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.launch
import pe.vivatech.traza.core.domain.Geiger
import pe.vivatech.traza.core.domain.ProximitySmoother
import pe.vivatech.traza.core.reader.ReadProfile
import pe.vivatech.traza.core.reader.RfidReader
import javax.inject.Inject

data class LocateState(
    val epc: String = "",
    val productName: String = "",
    val proximity: Int = 0,
    val isSearching: Boolean = false,
) {
    val label: String get() = Geiger.label(proximity)

    val beepIntervalMs: Long get() = Geiger.beepIntervalMs(proximity)
}

/** Modo búsqueda. Ver `docs/09` §7. */
@HiltViewModel
class LocateViewModel @Inject constructor(
    private val reader: RfidReader,
) : ViewModel() {

    private val smoother = ProximitySmoother()
    private var job: Job? = null

    private val _state = MutableStateFlow(LocateState())
    val state: StateFlow<LocateState> = _state.asStateFlow()

    fun locate(epc: String, productName: String) {
        stop()
        smoother.reset()

        _state.value = LocateState(epc = epc, productName = productName, isSearching = true)

        job = viewModelScope.launch {
            reader.connect()
            reader.applyProfile(ReadProfile.BUSQUEDA)

            reader.locate(epc)
                // Suavizado antes de tocar la pantalla: la proximidad cruda
                // salta veinte puntos entre lecturas aunque la mano no se
                // mueva, y con eso el pitido tartamudea y nadie se fía.
                .map { smoother.next(it) }
                .collect { proximity -> _state.value = _state.value.copy(proximity = proximity) }
        }
    }

    fun stop() {
        job?.cancel()
        job = null
        _state.value = _state.value.copy(isSearching = false, proximity = 0)

        viewModelScope.launch { reader.stopInventory() }
    }

    override fun onCleared() {
        super.onCleared()
        stop()
    }
}
