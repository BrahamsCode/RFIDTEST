# 09 — Aplicación de mano (Android nativo)

Ver ADR-008: Kotlin nativo, no híbrido.

---

## 1. El contexto de uso manda

Quien usa esta aplicación:

- Está **de pie**, moviéndose, con un equipo de 800 g en una mano.
- Mira la pantalla **de reojo**; su atención está en la estantería.
- Trabaja en tramos de **20 a 45 minutos** sin parar.
- Puede tener **rotación alta**: formación de 30 minutos como máximo.
- A veces no tiene **red**.

Consecuencias de diseño que no son negociables:

| Regla | Motivo |
|---|---|
| **El gatillo físico hace lo obvio** | Escanear. Nunca abre menús ni confirma diálogos |
| **Tipografía grande**: números clave a 48 sp+ | Se leen de reojo, a 40 cm, en movimiento |
| **Un solo número dominante por pantalla** | El contador de escaneados. Todo lo demás es secundario |
| **Realimentación háptica y sonora** | La vista está en la estantería, no en la pantalla |
| **Nunca bloquear por red** | Todo escribe primero en local |
| **Sin diálogos modales durante el escaneo** | Interrumpen el flujo y se descartan sin leer |
| **Modo oscuro por defecto** | Almacenes con poca luz; menos consumo de batería |

---

## 2. Pila técnica

| Preocupación | Elección |
|---|---|
| Lenguaje | Kotlin |
| Interfaz | Jetpack Compose |
| Arquitectura | MVVM + casos de uso |
| Base local | Room (SQLite) |
| Red | Retrofit + OkHttp + Moshi |
| Sincronización | WorkManager (tareas con restricciones de red) |
| Inyección | Hilt |
| SDK RFID | Capa de abstracción sobre Zebra RFID API3 / Chainway SDK |
| Mínimo Android | API 26 (Android 8) — cubre los handhelds industriales en uso |

---

## 3. Abstracción del lector

El SDK del fabricante se aísla tras una interfaz. Cambiar de Chainway (Fase 0) a Zebra (Fase 1) no debe tocar la interfaz de usuario.

```kotlin
// core/reader/RfidReader.kt
interface RfidReader {
    val events: Flow<ReaderEvent>

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

    val batteryLevel: StateFlow<Int>
    val isConnected: StateFlow<Boolean>
}

sealed interface ReaderEvent {
    data class TagsRead(val reads: List<TagRead>) : ReaderEvent
    data class Error(val message: String, val cause: Throwable?) : ReaderEvent
    data object TriggerPressed : ReaderEvent
    data object TriggerReleased : ReaderEvent
    data object Disconnected : ReaderEvent
}

data class TagRead(
    val epc: String,
    val tid: String?,
    val rssi: Int,
    val antenna: Int,
    val seenAt: Long,
    val readCount: Int,
)
```

### Implementación Zebra (extracto)

```kotlin
class ZebraRfidReader @Inject constructor(
    @ApplicationContext private val context: Context,
) : RfidReader, RfidEventsListener {

    private var api: RFIDReader? = null
    private val _events = MutableSharedFlow<ReaderEvent>(extraBufferCapacity = 256)
    override val events = _events.asSharedFlow()

    override suspend fun connect(): Result<Unit> = withContext(Dispatchers.IO) {
        runCatching {
            val readers = Readers(context, ENUM_TRANSPORT.SERVICE_SERIAL)
            val device = readers.GetAvailableRFIDReaderList().firstOrNull()
                ?: error("No se detectó ningún lector conectado.")

            api = device.rfidReader.apply {
                connect()
                Events.addEventsListener(this@ZebraRfidReader)
                Events.setTagReadEvent(true)
                Events.setHandheldEvent(true)          // gatillo físico
                Events.setBatteryEvent(true)
                Events.setInventoryStartEvent(true)
                Events.setInventoryStopEvent(true)
                // Notificar cada 50 tags o cada 250 ms: equilibrio entre
                // fluidez del contador y sobrecarga de eventos.
                Events.setAttachTagDataWithReadEvent(false)
            }
        }
    }

    override suspend fun applyProfile(profile: ReadProfile): Result<Unit> =
        withContext(Dispatchers.IO) {
            runCatching {
                val reader = api ?: error("Lector no conectado.")

                // Potencia: el SDK trabaja con índices de una tabla, no con dBm.
                val powerLevels = reader.ReaderCapabilities.transmitPowerLevelValues
                val index = powerLevels.indexOfFirst { it >= profile.txPowerDbm * 10 }
                    .coerceAtLeast(0)

                reader.Config.Antennas.getAntennaRfConfig(1).apply {
                    transmitPowerIndex = index
                    setrfModeTableIndex(0)
                    tari = 0
                    reader.Config.Antennas.setAntennaRfConfig(1, this)
                }

                reader.Config.Antennas.getSingulationControl(1).apply {
                    session = SESSION.valueOf("SESSION_S${profile.session}")
                    Action.inventoryState = INVENTORY_STATE.valueOf("INVENTORY_STATE_${profile.target}")
                    Action.slValue = SL_FLAG.SL_ALL
                    tagPopulation = profile.tagPopulation.toShort()
                    reader.Config.Antennas.setSingulationControl(1, this)
                }
            }
        }

    override fun eventReadNotify(event: RfidReadEvents) {
        val reads = api?.Actions?.getReadTags(100)?.map { tag ->
            TagRead(
                epc = tag.tagID.uppercase(),
                tid = tag.memoryBankData?.takeIf { it.isNotBlank() }?.uppercase(),
                rssi = tag.peakRSSI.toInt(),
                antenna = tag.antennaID.toInt(),
                seenAt = System.currentTimeMillis(),
                readCount = tag.tagSeenCount.toInt(),
            )
        } ?: return

        _events.tryEmit(ReaderEvent.TagsRead(reads))
    }

    /** El gatillo físico: sin él, la aplicación es inutilizable en la práctica. */
    override fun eventStatusNotify(event: RfidStatusEvents) {
        when (event.StatusEventData.HandheldTriggerEventData?.handheldEvent) {
            HANDHELD_TRIGGER_EVENT_TYPE.HANDHELD_TRIGGER_PRESSED  ->
                _events.tryEmit(ReaderEvent.TriggerPressed)
            HANDHELD_TRIGGER_EVENT_TYPE.HANDHELD_TRIGGER_RELEASED ->
                _events.tryEmit(ReaderEvent.TriggerReleased)
            else -> Unit
        }
    }
}
```

---

## 4. Modos de operación

La aplicación tiene **cinco modos**. Cada uno usa un perfil de lectura distinto y una pantalla distinta. No hay un "modo genérico".

| Modo | Perfil Gen2 | Potencia | Qué hace |
|---|---|---|---|
| **Inventario** | S2, target A | 27–30 dBm | Barrido masivo para un ciclo |
| **Tarado** | S0, target A | 15–18 dBm | Lee **un** tag cercano y lo asocia a un SKU |
| **Búsqueda** (Geiger) | S0 | Variable | Localiza una prenda concreta por proximidad |
| **Consulta** | S0 | 18 dBm | Lee un tag y muestra su ficha |
| **Recepción** | S1 | 27 dBm | Cuenta contra una orden de compra |

> La **potencia baja en modo tarado** es deliberada y contraintuitiva: quieres leer **solo** la prenda que tienes en la mano, no las cincuenta de la caja. Es el error más común al implementar: tarar con potencia alta asocia el SKU al tag equivocado, y ese error es prácticamente indetectable después.

---

## 5. Pantalla de inventario

```
┌─────────────────────────────────┐
│ ◀  Ciclo INV-032      🔋 78%  ⚡ │
│    Sala principal               │
├─────────────────────────────────┤
│                                 │
│                                 │
│           2 847                 │  ← 72 sp, tabular
│         escaneados              │
│                                 │
│      +412 en esta pasada        │
│                                 │
├─────────────────────────────────┤
│ ████████████████░░░░░  81 %     │
│ 2 847 de 3 512 esperados        │
├─────────────────────────────────┤
│  [ Cambiar zona ]  [ Pausar ]   │
├─────────────────────────────────┤
│ ● Sincronizado   ○ 0 pendientes │
└─────────────────────────────────┘

        Mantener el gatillo para escanear
```

```kotlin
@Composable
fun InventoryScreen(viewModel: InventoryViewModel = hiltViewModel()) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val haptics = LocalHapticFeedback.current

    // Realimentación al encontrar tags nuevos: quien barre no mira la pantalla.
    LaunchedEffect(state.newTagsInBurst) {
        if (state.newTagsInBurst > 0) {
            haptics.performHapticFeedback(HapticFeedbackType.TextHandleMove)
        }
    }

    // La pantalla no se apaga durante un barrido de 30 minutos.
    KeepScreenOn(enabled = state.isScanning)

    Scaffold(topBar = { CycleTopBar(state) }) { padding ->
        Column(
            modifier = Modifier.padding(padding).fillMaxSize(),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Spacer(Modifier.weight(1f))

            Text(
                text = state.scannedCount.formatThousands(),
                style = MaterialTheme.typography.displayLarge.copy(
                    fontSize = 72.sp,
                    fontFeatureSettings = "tnum",
                ),
            )
            Text("escaneados", style = MaterialTheme.typography.titleMedium)

            AnimatedVisibility(visible = state.newTagsInBurst > 0) {
                Text(
                    "+${state.newTagsInBurst} en esta pasada",
                    color = MaterialTheme.colorScheme.primary,
                )
            }

            Spacer(Modifier.weight(1f))

            CycleProgressBar(
                scanned = state.scannedCount,
                expected = state.expectedCount,
            )

            Row(Modifier.padding(16.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                OutlinedButton(onClick = viewModel::openZonePicker, Modifier.weight(1f).height(56.dp)) {
                    Text("Cambiar zona")
                }
                Button(onClick = viewModel::pause, Modifier.weight(1f).height(56.dp)) {
                    Text(if (state.isPaused) "Reanudar" else "Pausar")
                }
            }

            SyncStatusBar(
                synced = state.pendingUploads == 0,
                pending = state.pendingUploads,
            )
        }
    }
}
```

### ViewModel: deduplicación local

```kotlin
@HiltViewModel
class InventoryViewModel @Inject constructor(
    private val reader: RfidReader,
    private val scanRepo: ScanRepository,
    private val syncScheduler: SyncScheduler,
) : ViewModel() {

    /**
     * Conjunto de EPC ya vistos EN ESTE CICLO. Con 20 000 tags, un HashSet
     * de cadenas de 24 caracteres ocupa unos pocos MB: perfectamente asumible,
     * y evita ir a la base local en cada lectura (miles por segundo).
     */
    private val seenEpcs = HashSet<String>(32_000)

    init {
        viewModelScope.launch {
            reader.events
                .filterIsInstance<ReaderEvent.TagsRead>()
                .collect { event -> handleReads(event.reads) }
        }

        viewModelScope.launch {
            reader.events
                .filterIsInstance<ReaderEvent.TriggerPressed>()
                .collect { startBurst() }
        }
    }

    private suspend fun handleReads(reads: List<TagRead>) {
        val fresh = reads.filter { seenEpcs.add(it.epc) }
        if (fresh.isEmpty()) return

        // Escritura local primero. Siempre. La red viene después.
        scanRepo.insertAll(fresh.map { it.toEntity(cycleId, zoneId) })

        _state.update {
            it.copy(
                scannedCount = seenEpcs.size,
                newTagsInBurst = it.newTagsInBurst + fresh.size,
            )
        }

        syncScheduler.requestSync()   // WorkManager decide cuándo
    }
}
```

---

## 6. Sincronización offline

```kotlin
@HiltWorker
class UploadScansWorker @AssistedInject constructor(
    @Assisted context: Context,
    @Assisted params: WorkerParameters,
    private val scanDao: ScanDao,
    private val api: TrazaApi,
) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val batch = scanDao.pending(limit = 500)
        if (batch.isEmpty()) return Result.success()

        return try {
            val batchId = UUID.randomUUID().toString()

            api.ingestReads(
                IngestRequest(
                    deviceCode = deviceCode(),
                    batchId = batchId,
                    inventoryCycleId = batch.first().cycleId,
                    reads = batch.map { it.toDto() },
                )
            )

            scanDao.markUploaded(batch.map { it.id })
            // Si quedan más, se encadena otra ejecución inmediata.
            if (scanDao.pendingCount() > 0) Result.retry() else Result.success()

        } catch (e: IOException) {
            Result.retry()          // sin red: WorkManager reintenta con backoff
        } catch (e: HttpException) {
            when (e.code()) {
                in 500..599 -> Result.retry()
                401, 403    -> { notifyAuthProblem(); Result.failure() }
                // 4xx del cliente: el lote es inválido. Reintentar no lo arregla.
                // Se marca como fallido y se conserva para diagnóstico.
                else        -> { scanDao.markFailed(batch.map { it.id }, e.message()); Result.failure() }
            }
        }
    }
}
```

Configuración de la tarea:

```kotlin
val request = OneTimeWorkRequestBuilder<UploadScansWorker>()
    .setConstraints(
        Constraints.Builder()
            .setRequiredNetworkType(NetworkType.CONNECTED)
            .build()
    )
    .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 15, TimeUnit.SECONDS)
    .build()

WorkManager.getInstance(context)
    .enqueueUniqueWork("upload-scans", ExistingWorkPolicy.KEEP, request)
```

> `ExistingWorkPolicy.KEEP` es importante: durante un barrido se llama a `requestSync()` cientos de veces por minuto. Sin `KEEP`, se encolarían cientos de trabajos.

---

## 7. Modo búsqueda (Geiger)

Localizar una prenda concreta en la tienda. Es la función que más sorprende a los usuarios nuevos y la que más justifica el equipo ante la dirección.

```
┌─────────────────────────────────┐
│ ◀  Buscar prenda                │
├─────────────────────────────────┤
│  Polera Oversize Negra · M      │
│  3035 D919 080C 0E40 3B9A CA2A  │
├─────────────────────────────────┤
│                                 │
│         ◜◝ ◜◝ ◜◝ ◜◝              │
│        ((( 87 )))               │  ← círculos concéntricos animados
│         ◟◞ ◟◞ ◟◞ ◟◞              │
│                                 │
│         MUY CERCA               │
│                                 │
├─────────────────────────────────┤
│ Pitido acelera al acercarse     │
│              [ Detener ]        │
└─────────────────────────────────┘
```

```kotlin
class LocateViewModel @Inject constructor(
    private val reader: RfidReader,
    private val toneGenerator: ToneGenerator,
) : ViewModel() {

    fun locate(epc: String) {
        viewModelScope.launch {
            reader.locate(epc)
                // Suavizado: la proximidad cruda salta mucho y marea.
                .map { proximity -> smoother.next(proximity) }
                .collect { proximity ->
                    _state.update { it.copy(proximity = proximity) }
                    emitTone(proximity)
                }
        }
    }

    /**
     * El intervalo entre pitidos cae de 800 ms (lejos) a 60 ms (encima).
     * Es lo que convierte una cifra en pantalla en una herramienta usable
     * sin mirar.
     */
    private suspend fun emitTone(proximity: Int) {
        val interval = lerp(800, 60, proximity / 100f).toLong()
        toneGenerator.startTone(ToneGenerator.TONE_PROP_BEEP, 40)
        delay(interval)
    }
}
```

---

## 8. Modo tarado

Asociar un EPC físico a un SKU. Es la operación que más veces se repite y donde más caro sale un error.

```
┌─────────────────────────────────┐
│ ◀  Tarar prendas                │
├─────────────────────────────────┤
│  SKU activo                     │
│  ┌───────────────────────────┐  │
│  │ Polera Oversize Negra     │  │
│  │ Talla M · SKU 12345       │  │
│  │ S/ 89.90                  │  │  ← se fija una vez
│  └───────────────────────────┘  │
│           [ Cambiar SKU ]       │
├─────────────────────────────────┤
│                                 │
│            47                   │
│      taradas en esta sesión     │
│                                 │
│  Última: 3035…CA2A  hace 3 s ✓  │
│                                 │
├─────────────────────────────────┤
│  Potencia baja · solo lee la    │
│  prenda que tienes en la mano   │
└─────────────────────────────────┘
```

Flujo:

1. El operario elige el SKU una vez (por búsqueda o escaneando el código de barras del albarán).
2. Coge una prenda, aprieta el gatillo.
3. La aplicación lee **un** tag a potencia baja.
4. Valida: ¿el EPC ya está tarado? Si sí → aviso sonoro de error y no se asocia.
5. Guarda localmente y suma al contador.
6. Repite desde el paso 2 sin tocar la pantalla.

```kotlin
suspend fun commissionOne(): CommissionResult {
    val read = reader.readSingle(timeoutMs = 2500).getOrElse {
        return CommissionResult.NoTag
    }

    // Comprobación local: ¿ya conocemos este EPC?
    tagDao.findByEpc(read.epc)?.let { existing ->
        return if (existing.productVariantId == activeVariantId) {
            CommissionResult.AlreadyCommissionedSameSku
        } else {
            // Caso grave: este tag ya pertenece a otro producto.
            CommissionResult.Conflict(existing.productVariantId)
        }
    }

    tagDao.insert(
        TagEntity(
            epc = read.epc,
            tid = read.tid,
            productVariantId = activeVariantId,
            state = "en_stock",
            locationId = activeLocationId,
            zoneId = activeZoneId,
            commissionedAt = System.currentTimeMillis(),
            pendingSync = true,
        )
    )

    syncScheduler.requestSync()
    return CommissionResult.Ok(read.epc)
}
```

Señales sonoras distintas por resultado — el operario aprende a distinguirlas sin mirar:

| Resultado | Señal |
|---|---|
| `Ok` | Un pitido corto agudo |
| `AlreadyCommissionedSameSku` | Dos pitidos cortos |
| `Conflict` | Pitido grave largo + vibración larga + **la pantalla sí exige confirmación** |
| `NoTag` | Ningún sonido (probablemente no apretó bien; no hay que castigarlo con ruido) |

---

## 9. Gestión de batería y sesión larga

| Problema | Solución |
|---|---|
| Barrido de 40 min agota la batería | Aviso a 25 % y a 15 %; el estado del ciclo persiste, se puede cambiar batería y seguir |
| La pantalla se apaga | `KeepScreenOn` solo mientras se escanea; se libera al pausar |
| El lector se desconecta al suspenderse | Servicio en primer plano con notificación persistente durante el ciclo |
| Se cierra la aplicación por memoria | El estado del ciclo está en Room, no en memoria. Al reabrir, se reanuda donde estaba |
| Cambio de operario a mitad de ciclo | El ciclo pertenece a la tienda, no al usuario. Se registra el cambio de operario en el ciclo |

---

## 10. Configuración y despliegue

| Aspecto | Decisión |
|---|---|
| Distribución | APK firmado, instalado por MDM o manualmente. **No** Play Store: son equipos corporativos |
| Actualización | MDM (Zebra StageNow, SOTI) o comprobación de versión propia con descarga directa |
| Alta del dispositivo | El equipo se registra escaneando un QR generado desde la web (contiene URL, código de dispositivo y token de un solo uso) |
| Modo quiosco | Recomendado: la aplicación es lo único que se ejecuta. Evita que el equipo termine con juegos y WhatsApp |
| Cifrado | Cifrado de disco obligatorio; PIN de dispositivo |
| Pérdida o robo | Borrado remoto por MDM; el token del dispositivo se revoca desde la web |

### Alta por QR

```json
{
  "v": 1,
  "url": "https://traza.ejemplo.pe",
  "device_code": "HH-LIM01-02",
  "enrollment_token": "eyJ0eXAiOi...",
  "location_id": 1
}
```

El token de alta es de un solo uso y caduca en 15 minutos. Al canjearlo, el dispositivo recibe su token permanente, que se guarda en el `EncryptedSharedPreferences` de Android.

---

## 11. Pruebas

| Nivel | Herramienta | Qué |
|---|---|---|
| Unitarias | JUnit + Turbine | ViewModels, deduplicación, lógica de estados |
| Lector simulado | Implementación falsa de `RfidReader` | Toda la interfaz sin hardware |
| Instrumentadas | Compose UI Test | Navegación, pantallas críticas |
| Campo | Manual, protocolo escrito | Tasa de lectura real, batería, ergonomía |

```kotlin
class FakeRfidReader(
    private val population: List<String>,
    private val missRate: Float = 0.03f,
    private val readsPerSecond: Int = 300,
) : RfidReader {

    override val events = flow {
        while (currentCoroutineContext().isActive) {
            val batch = population
                .filter { Random.nextFloat() > missRate }
                .shuffled()
                .take(readsPerSecond / 10)
                .map { TagRead(it, null, -50 - Random.nextInt(25), 1, System.currentTimeMillis(), 1) }

            emit(ReaderEvent.TagsRead(batch))
            delay(100)
        }
    }
}
```

> Con este falso, un desarrollador de interfaz puede construir y probar toda la aplicación sin tener nunca un lector delante. Es lo que permite paralelizar el trabajo mientras el hardware está en aduana.
