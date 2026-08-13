package pe.vivatech.traza.core.domain

import pe.vivatech.traza.core.reader.ReadProfile
import pe.vivatech.traza.core.reader.RfidReader
import pe.vivatech.traza.core.reader.TagRead

/** Resultado de tarar una prenda. Ver `docs/09` §8. */
sealed interface CommissionResult {
    data class Ok(val epc: String, val tid: String?) : CommissionResult

    /** El mismo EPC ya está tarado a este mismo SKU. Molesto, no grave. */
    data class AlreadyCommissionedSameSku(val epc: String) : CommissionResult

    /**
     * El EPC pertenece a otro producto. Es el caso grave: si se sobrescribe
     * en silencio, el inventario queda mal y el error es prácticamente
     * indetectable después.
     */
    data class Conflict(val epc: String, val existingVariantId: Long) : CommissionResult

    /** No se leyó nada. Probablemente no apretó bien el gatillo. */
    data object NoTag : CommissionResult
}

/**
 * Señal que oye el operario. Son distintas a propósito: quien tara 300
 * prendas seguidas no mira la pantalla, y aprende a distinguirlas.
 */
enum class FeedbackSignal {
    /** Un pitido corto agudo. */
    SHORT_HIGH,

    /** Dos pitidos cortos. */
    DOUBLE_SHORT,

    /** Pitido grave largo y vibración larga. */
    LONG_LOW_WITH_VIBRATION,

    /**
     * Silencio. No apretar bien el gatillo no es un error del operario y
     * castigarlo con un pitido en cada intento vuelve el trabajo insufrible.
     */
    SILENT,
}

fun CommissionResult.signal(): FeedbackSignal = when (this) {
    is CommissionResult.Ok -> FeedbackSignal.SHORT_HIGH
    is CommissionResult.AlreadyCommissionedSameSku -> FeedbackSignal.DOUBLE_SHORT
    is CommissionResult.Conflict -> FeedbackSignal.LONG_LOW_WITH_VIBRATION
    CommissionResult.NoTag -> FeedbackSignal.SILENT
}

/** Solo el conflicto detiene el trabajo y pide una decisión explícita. */
fun CommissionResult.requiresConfirmation(): Boolean = this is CommissionResult.Conflict

/** Registro local de tags ya tarados. En `:app` lo implementa Room. */
interface CommissionedTagStore {
    /** Variante a la que ya está asociado este EPC, o null si es nuevo. */
    fun variantFor(epc: String): Long?

    fun save(
        epc: String,
        tid: String?,
        productVariantId: Long,
        locationId: Long,
        zoneId: Long?,
        commissionedAt: Long,
    )
}

data class CommissioningState(
    val activeVariantId: Long,
    val commissionedInSession: Int = 0,
    val lastEpc: String? = null,
    val lastAt: Long? = null,
    val pendingConflict: CommissionResult.Conflict? = null,
)

/**
 * Tarado de prendas.
 *
 * La operación que más se repite y donde más caro sale equivocarse: si se
 * asocia el SKU al tag de la prenda de al lado, el error viaja hasta el
 * primer inventario y para entonces ya nadie sabe de dónde salió.
 */
class CommissioningSession(
    private val reader: RfidReader,
    private val store: CommissionedTagStore,
    activeVariantId: Long,
    private val locationId: Long,
    private val zoneId: Long? = null,
    private val now: () -> Long = System::currentTimeMillis,
) {
    var state: CommissioningState = CommissioningState(activeVariantId = activeVariantId)
        private set

    /**
     * El perfil se aplica al entrar en el modo, no en cada lectura.
     *
     * La potencia baja es lo contrario de lo intuitivo y es lo que hace
     * correcto el modo: hay que leer **solo** la prenda que se tiene en la
     * mano, no las cincuenta de la caja abierta al lado.
     */
    suspend fun start(): Result<Unit> = reader.applyProfile(ReadProfile.TARADO)

    suspend fun commissionOne(timeoutMs: Long = 2500): CommissionResult {
        val read: TagRead = reader.readSingle(timeoutMs).getOrElse {
            return CommissionResult.NoTag
        }

        store.variantFor(read.epc)?.let { existing ->
            val result = if (existing == state.activeVariantId) {
                CommissionResult.AlreadyCommissionedSameSku(read.epc)
            } else {
                CommissionResult.Conflict(read.epc, existing)
            }

            if (result is CommissionResult.Conflict) {
                state = state.copy(pendingConflict = result)
            }

            return result
        }

        val at = now()

        store.save(
            epc = read.epc,
            tid = read.tid,
            productVariantId = state.activeVariantId,
            locationId = locationId,
            zoneId = zoneId,
            commissionedAt = at,
        )

        state = state.copy(
            commissionedInSession = state.commissionedInSession + 1,
            lastEpc = read.epc,
            lastAt = at,
        )

        return CommissionResult.Ok(read.epc, read.tid)
    }

    /**
     * El operario confirma que quiere reasignar el tag a la variante activa.
     * Solo se llega aquí desde un diálogo explícito: es la única pantalla del
     * modo que interrumpe el ritmo, y lo hace porque debe.
     */
    fun confirmReassignment(): CommissionResult {
        val conflict = state.pendingConflict ?: return CommissionResult.NoTag
        val at = now()

        store.save(
            epc = conflict.epc,
            tid = null,
            productVariantId = state.activeVariantId,
            locationId = locationId,
            zoneId = zoneId,
            commissionedAt = at,
        )

        state = state.copy(
            commissionedInSession = state.commissionedInSession + 1,
            lastEpc = conflict.epc,
            lastAt = at,
            pendingConflict = null,
        )

        return CommissionResult.Ok(conflict.epc, null)
    }

    fun dismissConflict() {
        state = state.copy(pendingConflict = null)
    }

    /** Cambiar de SKU no reinicia el contador de la sesión, que es del turno. */
    fun changeVariant(variantId: Long) {
        state = state.copy(activeVariantId = variantId, pendingConflict = null)
    }
}
