package pe.vivatech.traza.core.domain

import kotlin.math.abs
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertTrue

/** Tarea 5.5: modo búsqueda. */
class GeigerTest {

    @Test
    fun `el intervalo va de 800 ms lejos a 60 ms encima`() {
        assertEquals(Geiger.FAR_INTERVAL_MS, Geiger.beepIntervalMs(0))
        assertEquals(Geiger.NEAR_INTERVAL_MS, Geiger.beepIntervalMs(100))
    }

    @Test
    fun `el intervalo no sube nunca al acercarse`() {
        var previous = Long.MAX_VALUE

        for (p in 0..100) {
            val interval = Geiger.beepIntervalMs(p)
            assertTrue(interval <= previous, "En p=$p el pitido se frenó: $previous → $interval.")
            previous = interval
        }
    }

    @Test
    fun `el tramo final es el que mas cambia`() {
        /*
         * Con una recta, los últimos veinte puntos —cuando ya se está delante
         * de la estantería correcta— apenas se distinguirían entre sí, que es
         * justo donde hace falta resolución.
         */
        val primeros20 = Geiger.beepIntervalMs(0) - Geiger.beepIntervalMs(20)
        val ultimos20 = Geiger.beepIntervalMs(80) - Geiger.beepIntervalMs(100)

        assertTrue(ultimos20 > primeros20, "primeros=$primeros20 ms, últimos=$ultimos20 ms")
    }

    @Test
    fun `la proximidad fuera de rango no rompe nada`() {
        assertEquals(Geiger.FAR_INTERVAL_MS, Geiger.beepIntervalMs(-40))
        assertEquals(Geiger.NEAR_INTERVAL_MS, Geiger.beepIntervalMs(500))
    }

    @Test
    fun `hay cuatro tramos de texto y el ultimo llega al cien`() {
        assertEquals("SIN SEÑAL", Geiger.label(0))
        assertEquals("CERCA", Geiger.label(40))
        assertEquals("MUY CERCA", Geiger.label(70))
        assertEquals("LO TIENES DELANTE", Geiger.label(100))
    }

    @Test
    fun `el suavizado quita el temblor del multitrayecto`() {
        // Lecturas que saltan 60 puntos sin que la mano se mueva: es lo que
        // hace un lector UHF en una tienda con estanterías metálicas.
        val smoother = ProximitySmoother()
        val crudas = listOf(50, 10, 70, 20, 65, 15, 60)

        val suaves = crudas.map { smoother.next(it) }

        val saltoCrudo = crudas.zipWithNext().maxOf { (a, b) -> abs(b - a) }
        val saltoSuave = suaves.zipWithNext().maxOf { (a, b) -> abs(b - a) }

        assertTrue(saltoSuave < saltoCrudo / 2, "crudo=$saltoCrudo suave=$saltoSuave")
    }

    @Test
    fun `la primera muestra se toma tal cual`() {
        // Arrancar desde cero haría subir la aguja sola durante el primer
        // segundo, con la prenda ya delante.
        assertEquals(80, ProximitySmoother().next(80))
    }

    @Test
    fun `converge al valor real si el operario se queda quieto`() {
        val smoother = ProximitySmoother()
        smoother.next(0)

        repeat(40) { smoother.next(90) }

        assertEquals(90, smoother.next(90))
    }

    @Test
    fun `reset olvida el rastro anterior`() {
        val smoother = ProximitySmoother()
        repeat(10) { smoother.next(90) }

        smoother.reset()

        assertEquals(5, smoother.next(5))
    }

    @Test
    fun `el suavizado nunca se sale de cero a cien`() {
        val smoother = ProximitySmoother()

        listOf(-50, 300, 0, 100, -1).forEach {
            val value = smoother.next(it)
            assertTrue(value in 0..100, "Devolvió $value para $it.")
        }
    }
}
