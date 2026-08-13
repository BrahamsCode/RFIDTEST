package pe.vivatech.traza.core.reader

import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.runTest
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertNotEquals
import kotlin.test.assertNull
import kotlin.test.assertNotNull
import kotlin.test.assertTrue
import kotlin.test.assertFalse

@OptIn(ExperimentalCoroutinesApi::class)
class FakeRfidReaderTest {

    private fun reader(seed: Int = 1, population: Int = 200) =
        FakeRfidReader(scope = TestScope(), populationSize = population, seed = seed)

    @Test
    fun `no lee mientras no esta conectado`() = runTest {
        val r = reader()
        assertFalse(r.isConnected.value)
        assertTrue(r.readSingle().isFailure)
        assertTrue(r.startInventory().isFailure)
    }

    @Test
    fun `conecta y entrega lecturas`() = runTest {
        val r = reader()
        assertTrue(r.connect().isSuccess)
        assertTrue(r.isConnected.value)
        assertTrue(r.readSingle().isSuccess)
    }

    @Test
    fun `es reproducible con semilla fija`() {
        val a = reader(seed = 42).population
        val b = reader(seed = 42).population
        assertEquals(a, b)
    }

    @Test
    fun `produce poblaciones distintas con semillas distintas`() {
        assertNotEquals(reader(seed = 1).population, reader(seed = 2).population)
    }

    @Test
    fun `todos los EPC generados llevan la mascara de la casa`() {
        assertTrue(reader().population.all { it.startsWith("3035D9") && it.length == 24 })
    }

    @Test
    fun `solo adjunta TID cuando el perfil lo pide`() = runTest {
        val r = reader()
        r.connect()

        r.applyProfile(ReadProfile.INVENTARIO)
        assertNull(r.readSingle().getOrThrow().tid)

        r.applyProfile(ReadProfile.TARADO)
        assertNotNull(r.readSingle().getOrThrow().tid)
    }

    @Test
    fun `rechaza escribir un EPC mal formado`() = runTest {
        val r = reader()
        r.connect()
        assertTrue(r.writeEpc("3035D9".padEnd(24, '0'), "NO-ES-HEX", null).isFailure)
        assertTrue(r.writeEpc("3035D9".padEnd(24, '0'), "3035D9".padEnd(24, 'A'), null).isSuccess)
    }

    @Test
    fun `el lote respeta la tasa de fallo de lectura`() {
        val r = reader(population = 1000)
        val burst = r.burst(size = 500)
        // Con missRate 0.03 se pierde algo, pero nunca la mayoría.
        assertTrue(burst.size in 400..500, "lote inesperado: ${burst.size}")
    }
}

class ReadProfileTest {

    @Test
    fun `el perfil de tarado usa potencia baja a proposito`() {
        // Leer la caja entera de la trastienda al tarar una prenda es el
        // fallo clásico; la potencia baja lo evita.
        assertTrue(ReadProfile.TARADO.txPowerDbm < ReadProfile.INVENTARIO.txPowerDbm)
        assertNotNull(ReadProfile.TARADO.rssiThreshold)
        assertTrue(ReadProfile.TARADO.readTid)
    }

    @Test
    fun `el inventario usa sesion S2`() {
        assertEquals(2, ReadProfile.INVENTARIO.session)
    }

    @Test
    fun `el tarado usa sesion S0 y es coherente con su minimo de lecturas`() {
        /*
         * Tabla de `docs/09` §4. No es un detalle: con una sesión que
         * persiste, el tag se calla tras la primera respuesta y el mínimo de
         * dos lecturas del perfil no se alcanzaría nunca. El perfil sería
         * imposible de satisfacer y no se taría ninguna prenda.
         */
        assertEquals(0, ReadProfile.TARADO.session)
        assertTrue(ReadProfile.TARADO.minReadCount >= 2)
    }

    @Test
    fun `rechaza una sesion Gen2 fuera de rango`() {
        val error = runCatching { ReadProfile.INVENTARIO.copy(session = 9) }.exceptionOrNull()
        assertTrue(error is IllegalArgumentException)
    }

    @Test
    fun `rechaza un target distinto de A o B`() {
        val error = runCatching { ReadProfile.INVENTARIO.copy(target = "C") }.exceptionOrNull()
        assertTrue(error is IllegalArgumentException)
    }
}
