package pe.vivatech.traza.core.reader

import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.flow
import kotlinx.coroutines.launch
import kotlin.math.abs
import kotlin.random.Random

/**
 * Lector falso. Permite desarrollar y probar la aplicación completa sin
 * hardware, que es el criterio de aceptación de la tarea 5.1.
 *
 * Es determinista con semilla fija: sin eso las pruebas de la app serían
 * intermitentes.
 */
class FakeRfidReader(
    private val scope: CoroutineScope,
    populationSize: Int = 500,
    private val epcPrefix: String = "3035D9",
    seed: Int = 1,
    private val missRate: Double = 0.03,
    private val burstSize: Int = 25,
    private val burstIntervalMs: Long = 250,
) : RfidReader {

    private val random = Random(seed)
    private val _events = MutableSharedFlow<ReaderEvent>(extraBufferCapacity = 256)
    private val _battery = MutableStateFlow(87)
    private val _connected = MutableStateFlow(false)

    private var profile: ReadProfile = ReadProfile.INVENTARIO
    private var inventoryJob: Job? = null

    val population: List<String> = buildPopulation(populationSize)

    override val events: Flow<ReaderEvent> = _events.asSharedFlow()
    override val batteryLevel = _battery.asStateFlow()
    override val isConnected = _connected.asStateFlow()

    override suspend fun connect(): Result<Unit> {
        _connected.value = true
        return Result.success(Unit)
    }

    override suspend fun disconnect() {
        stopInventory()
        _connected.value = false
        _events.emit(ReaderEvent.Disconnected)
    }

    override suspend fun applyProfile(profile: ReadProfile): Result<Unit> {
        if (!_connected.value) return Result.failure(IllegalStateException("Lector no conectado."))
        this.profile = profile
        return Result.success(Unit)
    }

    override suspend fun startInventory(): Result<Unit> {
        if (!_connected.value) return Result.failure(IllegalStateException("Lector no conectado."))
        if (inventoryJob?.isActive == true) return Result.success(Unit)

        inventoryJob = scope.launch {
            while (true) {
                _events.emit(ReaderEvent.TagsRead(nextBurst()))
                delay(burstIntervalMs)
            }
        }
        return Result.success(Unit)
    }

    override suspend fun stopInventory() {
        inventoryJob?.cancel()
        inventoryJob = null
    }

    override suspend fun readSingle(timeoutMs: Long): Result<TagRead> {
        if (!_connected.value) return Result.failure(IllegalStateException("Lector no conectado."))
        return Result.success(makeRead(population.random(random)))
    }

    override suspend fun writeEpc(
        currentEpc: String,
        newEpc: String,
        accessPassword: String?,
    ): Result<Unit> {
        if (!_connected.value) return Result.failure(IllegalStateException("Lector no conectado."))
        if (!newEpc.matches(HEX_24)) {
            return Result.failure(IllegalArgumentException("EPC inválido: $newEpc"))
        }
        return Result.success(Unit)
    }

    /**
     * Proximidad simulada: crece a medida que el operario "se acerca". La
     * pantalla de búsqueda la traduce en un pitido que acelera.
     */
    override fun locate(epc: String): Flow<Int> = flow {
        var proximity = 5
        while (true) {
            val drift = random.nextInt(-6, 14)
            proximity = (proximity + drift).coerceIn(0, 100)
            emit(proximity)
            delay(200)
        }
    }

    /** Emite un lote concreto sin temporizador. Lo usan las pruebas. */
    fun burst(size: Int = burstSize): List<TagRead> = nextBurst(size)

    private fun nextBurst(size: Int = burstSize): List<TagRead> =
        (0 until size).mapNotNull {
            // Fallo de lectura: la app debe tolerar huecos sin descuadrar el conteo.
            if (random.nextDouble() < missRate) null
            else makeRead(population[random.nextInt(population.size)])
        }

    private fun makeRead(epc: String) = TagRead(
        epc = epc,
        tid = if (profile.readTid) tidFor(epc) else null,
        rssi = -58 + random.nextInt(0, 20),
        antenna = 1,
        seenAt = System.currentTimeMillis(),
        readCount = 1 + random.nextInt(0, 5),
    )

    private fun buildPopulation(size: Int): List<String> {
        val out = LinkedHashSet<String>(size)
        while (out.size < size) {
            val body = (0 until (24 - epcPrefix.length))
                .map { HEX[random.nextInt(HEX.size)] }
                .joinToString("")
            out.add((epcPrefix + body).uppercase())
        }
        return out.toList()
    }

    private fun tidFor(epc: String): String =
        "E280" + abs(epc.hashCode()).toString(16).uppercase().padStart(20, '0').take(20)

    private companion object {
        val HEX = "0123456789ABCDEF".toCharArray()
        val HEX_24 = Regex("^[0-9A-Fa-f]{24}$")
    }
}
