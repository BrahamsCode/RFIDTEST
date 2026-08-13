package pe.vivatech.traza.core.domain

import kotlin.math.roundToInt

/**
 * Suavizado de la proximidad. Ver `docs/09` §7.
 *
 * La proximidad cruda de un lector UHF salta veinte puntos entre lecturas
 * consecutivas aunque la mano no se mueva: es multitrayecto, no movimiento.
 * Sin suavizar, el pitido tartamudea y la persona deja de fiarse de él, que
 * es lo único que hace usable el modo.
 */
class ProximitySmoother(
    /**
     * 0.35 es el compromiso: por debajo, la aguja va tan retrasada que uno
     * pasa por delante de la prenda sin enterarse; por encima, vuelve el
     * temblor que se quería quitar.
     */
    private val alpha: Double = 0.35,
) {
    init {
        require(alpha > 0.0 && alpha <= 1.0) { "alpha debe estar en (0, 1], no $alpha." }
    }

    private var value: Double? = null

    fun next(raw: Int): Int {
        val clamped = raw.coerceIn(0, 100).toDouble()

        // La primera muestra se toma tal cual: arrancar desde cero haría que
        // la aguja subiera sola durante el primer segundo, con la prenda ya
        // delante.
        val smoothed = value?.let { it + alpha * (clamped - it) } ?: clamped

        value = smoothed
        return smoothed.roundToInt().coerceIn(0, 100)
    }

    fun reset() {
        value = null
    }
}

/** Cómo se le cuenta al operario lo cerca que está, sin que mire la pantalla. */
object Geiger {

    const val FAR_INTERVAL_MS: Long = 800
    const val NEAR_INTERVAL_MS: Long = 60

    /**
     * Intervalo entre pitidos. Cae de 800 ms lejos a 60 ms encima.
     *
     * La interpolación es cuadrática, no lineal: con una recta, el cambio
     * audible se concentra al principio y los últimos veinte puntos —los que
     * de verdad importan, cuando ya se está delante de la estantería
     * correcta— apenas se distinguen entre sí.
     */
    fun beepIntervalMs(proximity: Int): Long {
        val p = proximity.coerceIn(0, 100) / 100.0
        val eased = p * p

        return (FAR_INTERVAL_MS + (NEAR_INTERVAL_MS - FAR_INTERVAL_MS) * eased)
            .roundToInt()
            .toLong()
    }

    /** Texto grande de la pantalla. Cuatro tramos: más matices no se retienen. */
    fun label(proximity: Int): String = when (proximity.coerceIn(0, 100)) {
        in 0..24 -> "SIN SEÑAL"
        in 25..54 -> "CERCA"
        in 55..84 -> "MUY CERCA"
        else -> "LO TIENES DELANTE"
    }
}
