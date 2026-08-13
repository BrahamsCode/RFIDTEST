package pe.vivatech.traza.core.domain

import pe.vivatech.traza.core.reader.TagRead

/**
 * Estado que pinta la pantalla de inventario. Ver `docs/09` §5.
 *
 * Un solo número domina la pantalla, porque quien barre mira la estantería y
 * no el equipo: el conteo se comprueba de reojo.
 */
data class InventoryState(
    val scannedCount: Int = 0,
    val expectedCount: Int = 0,
    val newTagsInBurst: Int = 0,
    val pendingUploads: Int = 0,
    val zoneId: Long? = null,
    val isScanning: Boolean = false,
    val isPaused: Boolean = false,
) {
    val progressPct: Double
        get() = if (expectedCount <= 0) 0.0 else (scannedCount * 100.0 / expectedCount)
}

/** Lo que la sesión decide guardar. El `:app` lo mete en Room. */
data class PendingScan(
    val epc: String,
    val tid: String?,
    val rssi: Int,
    val seenAt: Long,
    val cycleId: Long,
    val zoneId: Long?,
)

/**
 * Deduplicación local de un ciclo de inventario.
 *
 * Es lógica pura a propósito: sin `ViewModel`, sin corrutinas y sin Android.
 * El criterio de aceptación de la tarea 5.2 es que 20 000 EPC se deduplican
 * sin degradar la fluidez, y eso solo se puede afirmar si se puede medir, lo
 * que exige poder ejecutarlo sin emulador.
 */
class InventorySession(
    val cycleId: Long,
    expectedCount: Int = 0,
    zoneId: Long? = null,
    /**
     * Los ciclos grandes rondan los 20 000 tags. Reservar el hueco de
     * entrada evita una decena de rehash durante el barrido, que es justo
     * cuando el equipo no puede permitirse una pausa.
     */
    initialCapacity: Int = 32_000,
) {
    private val seenEpcs = HashSet<String>(initialCapacity)

    var state: InventoryState = InventoryState(
        expectedCount = expectedCount,
        zoneId = zoneId,
    )
        private set

    /** EPC distintos vistos en este ciclo. */
    val seenCount: Int get() = seenEpcs.size

    fun contains(epc: String): Boolean = seenEpcs.contains(epc)

    /**
     * Procesa un lote del lector y devuelve solo lo que hay que guardar.
     *
     * Devolver la lista en vez de escribirla es deliberado: la sesión no
     * sabe nada de Room ni de red, así que se puede ejercitar con 20 000
     * lecturas en una prueba de JUnit corriente.
     */
    fun accept(reads: List<TagRead>): List<PendingScan> {
        if (state.isPaused) return emptyList()

        val fresh = ArrayList<PendingScan>(reads.size)

        for (read in reads) {
            // `add` devuelve false si ya estaba: una sola operación de hash
            // por lectura, sin consultar la base local. Con miles de lecturas
            // por segundo, ir a SQLite en cada una es lo que hace que la
            // pantalla se atasque.
            if (!seenEpcs.add(read.epc)) continue

            fresh += PendingScan(
                epc = read.epc,
                tid = read.tid,
                rssi = read.rssi,
                seenAt = read.seenAt,
                cycleId = cycleId,
                zoneId = state.zoneId,
            )
        }

        if (fresh.isEmpty()) return emptyList()

        state = state.copy(
            scannedCount = seenEpcs.size,
            newTagsInBurst = state.newTagsInBurst + fresh.size,
        )

        return fresh
    }

    /** Gatillo apretado: empieza una pasada y el contador de la pasada vuelve a cero. */
    fun startBurst() {
        state = state.copy(isScanning = true, isPaused = false, newTagsInBurst = 0)
    }

    /** Gatillo soltado. El «+N en esta pasada» se mantiene: es lo que se acaba de leer. */
    fun endBurst() {
        state = state.copy(isScanning = false)
    }

    /**
     * Cambiar de zona no reinicia la deduplicación. Un tag ya contado no
     * vuelve a contarse porque el operario cambie de estantería, y contarlo
     * dos veces inflaría el ciclo con existencias que no están.
     */
    fun changeZone(zoneId: Long?) {
        state = state.copy(zoneId = zoneId, newTagsInBurst = 0)
    }

    fun pause() {
        state = state.copy(isPaused = true, isScanning = false)
    }

    fun resume() {
        state = state.copy(isPaused = false)
    }

    fun updatePending(count: Int) {
        state = state.copy(pendingUploads = count)
    }

    /**
     * Reanudación tras cerrarse la aplicación. `docs/09` §9: el estado vive
     * en Room, no en memoria, y al reabrir se sigue donde se estaba. Sin
     * esto, un cierre por memoria a mitad de barrido volvería a contar desde
     * cero todo lo ya leído.
     */
    fun restore(epcs: Collection<String>) {
        seenEpcs.addAll(epcs)
        state = state.copy(scannedCount = seenEpcs.size, newTagsInBurst = 0)
    }
}
