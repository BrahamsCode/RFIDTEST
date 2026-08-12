package pe.vivatech.traza.core.reader

import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.StateFlow

data class TagRead(
    val epc: String,
    val tid: String?,
    val rssi: Int,
    val antenna: Int,
    val seenAt: Long,
    val readCount: Int,
)

sealed interface ReaderEvent {
    data class TagsRead(val reads: List<TagRead>) : ReaderEvent
    data class Error(val message: String, val cause: Throwable?) : ReaderEvent
    data object TriggerPressed : ReaderEvent
    data object TriggerReleased : ReaderEvent
    data object Disconnected : ReaderEvent
}

/**
 * El SDK del fabricante se aísla tras esta interfaz. Cambiar de Chainway
 * (Fase 0) a Zebra (Fase 1) no debe tocar la interfaz de usuario.
 */
interface RfidReader {
    val events: Flow<ReaderEvent>
    val batteryLevel: StateFlow<Int>
    val isConnected: StateFlow<Boolean>

    suspend fun connect(): Result<Unit>
    suspend fun disconnect()
    suspend fun applyProfile(profile: ReadProfile): Result<Unit>
    suspend fun startInventory(): Result<Unit>
    suspend fun stopInventory()

    /** Lectura puntual del tag más cercano. Para tarado y consulta individual. */
    suspend fun readSingle(timeoutMs: Long = 3000): Result<TagRead>

    /** Escritura del banco EPC. Requiere access password. */
    suspend fun writeEpc(currentEpc: String, newEpc: String, accessPassword: String?): Result<Unit>

    /** Localización de un tag concreto: devuelve proximidad 0..100. */
    fun locate(epc: String): Flow<Int>
}
