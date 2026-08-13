# 07 — Middleware RFID (`traza-edge`)

Servicio Node.js/TypeScript desplegado en cada tienda. Ver ADR-001.

---

## 1. Responsabilidades

| Sí hace | No hace |
|---|---|
| Mantener conexiones LLRP persistentes con lectores fijos | Lógica de negocio (estados, movimientos, ventas) |
| Ejecutar el pipeline de filtrado de 5 etapas | Acceder a PostgreSQL directamente |
| Amortiguar en SQLite si la red cae | Decidir si una alarma es válida (solo aporta evidencia) |
| Publicar eventos a MQTT y a la API | Almacenar catálogo o stock |
| Enviar trabajos ZPL a la impresora | Interfaz de usuario |
| Emitir latidos de salud | — |

> **Regla**: si `traza-edge` desaparece y se reinstala desde cero, no se pierde ningún dato de negocio. Solo pierde lo que hubiera en su buffer local. Es un componente **desechable**.

---

## 2. Estructura del proyecto

```
traza-edge/
├── src/
│   ├── index.ts                   # arranque, señales, apagado ordenado
│   ├── config/
│   │   ├── schema.ts              # validación de env con zod
│   │   └── index.ts
│   ├── readers/
│   │   ├── ReaderAdapter.ts       # interfaz común
│   │   ├── LlrpAdapter.ts         # Zebra FX9600, Impinj, Chainway UR
│   │   ├── HttpWebhookAdapter.ts  # lectores que hacen POST
│   │   ├── SimulatorAdapter.ts    # ★ imprescindible para desarrollo
│   │   └── ReaderManager.ts       # supervisión y reconexión
│   ├── pipeline/
│   │   ├── Pipeline.ts
│   │   └── stages/
│   │       ├── EpcMaskStage.ts        [1]
│   │       ├── RssiThresholdStage.ts  [2]
│   │       ├── DedupeStage.ts         [3]
│   │       ├── MinCountStage.ts       [4]
│   │       └── DirectionStage.ts      [5]
│   ├── buffer/
│   │   ├── SqliteBuffer.ts
│   │   └── Flusher.ts
│   ├── transport/
│   │   ├── ApiClient.ts
│   │   ├── MqttClient.ts
│   │   └── retry.ts
│   ├── printing/
│   │   ├── ZplClient.ts
│   │   └── LabelJob.ts
│   ├── health/
│   │   └── Heartbeat.ts
│   └── types/
│       └── TagRead.ts
├── test/
├── Dockerfile
├── package.json
└── tsconfig.json
```

---

## 3. Tipos base

```typescript
// src/types/TagRead.ts

/** Lectura tal como sale del lector, sin procesar. */
export interface RawTagRead {
  epc: string;              // hex mayúscula, sin separadores
  tid?: string;
  antennaPort: number;
  rssi: number;             // dBm, típicamente entre -80 y -30
  phaseAngle?: number;      // radianes; útil para inferir dirección
  dopplerHz?: number;       // desplazamiento Doppler; >0 se acerca
  firstSeen: number;        // epoch ms
  lastSeen: number;
  readCount: number;
  readerId: string;
}

/** Lectura tras superar el pipeline. Lista para enviar. */
export interface ProcessedTagRead extends RawTagRead {
  sessionRef: string;
  inventoryCycleId?: number;
  zoneId?: number;
  direction?: 'entrada' | 'salida' | 'indeterminado';
  confidence?: number;      // 0..1
}

export interface PipelineContext {
  readerId: string;
  deviceKind: 'handheld' | 'lector_fijo';
  profile: ReadProfile;
  antennaConfig: Map<number, AntennaConfig>;
}

export interface ReadProfile {
  session: 0 | 1 | 2 | 3;
  target: 'A' | 'B';
  initialQ: number;
  txPowerDbm: number;
  dedupWindowMs: number;
  minReadCount: number;
  rssiThreshold?: number;
  readTid: boolean;
}
```

---

## 4. El pipeline

```typescript
// src/pipeline/Pipeline.ts
import type { RawTagRead, ProcessedTagRead, PipelineContext } from '../types/TagRead';

export interface Stage {
  readonly name: string;
  /** Devuelve la lectura (posiblemente enriquecida) o null para descartarla. */
  process(read: RawTagRead, ctx: PipelineContext): ProcessedTagRead | null;
}

export class Pipeline {
  private readonly counters = new Map<string, number>();

  constructor(private readonly stages: Stage[]) {}

  run(read: RawTagRead, ctx: PipelineContext): ProcessedTagRead | null {
    let current: any = read;

    for (const stage of this.stages) {
      current = stage.process(current, ctx);
      if (current === null) {
        this.increment(`dropped.${stage.name}`);
        return null;
      }
    }

    this.increment('passed');
    return current as ProcessedTagRead;
  }

  /** Métricas expuestas en /metrics para Prometheus. */
  snapshot(): Record<string, number> {
    return Object.fromEntries(this.counters);
  }

  private increment(key: string): void {
    this.counters.set(key, (this.counters.get(key) ?? 0) + 1);
  }
}
```

### Etapa [1] — Máscara EPC

```typescript
export class EpcMaskStage implements Stage {
  readonly name = 'epc_mask';

  constructor(
    private readonly allowedPrefixes: string[],
    private readonly testPrefix: string,
    private readonly isProduction: boolean,
  ) {}

  process(read: RawTagRead): RawTagRead | null {
    const epc = read.epc.toUpperCase();

    // En producción, un EPC de pruebas es un error grave: alguien dejó
    // un tag de laboratorio en la tienda. Se descarta y se registra.
    if (this.isProduction && epc.startsWith(this.testPrefix)) {
      return null;
    }

    return this.allowedPrefixes.some((p) => epc.startsWith(p)) ? read : null;
  }
}
```

### Etapa [2] — Umbral de RSSI

```typescript
export class RssiThresholdStage implements Stage {
  readonly name = 'rssi_threshold';

  process(read: RawTagRead, ctx: PipelineContext): RawTagRead | null {
    // El umbral por antena gana sobre el del perfil: la antena de caja
    // necesita ser mucho más selectiva que la de un pasillo.
    const antenna = ctx.antennaConfig.get(read.antennaPort);
    const threshold = antenna?.rssiThreshold ?? ctx.profile.rssiThreshold;

    if (threshold === undefined) return read;
    return read.rssi >= threshold ? read : null;
  }
}
```

### Etapa [3] — Deduplicación por ventana temporal

```typescript
export class DedupeStage implements Stage {
  readonly name = 'dedupe';

  /** clave: `${readerId}:${epc}` → epoch ms de la última emisión */
  private readonly lastEmitted = new Map<string, number>();
  private lastSweep = Date.now();

  process(read: RawTagRead, ctx: PipelineContext): RawTagRead | null {
    const key = `${read.readerId}:${read.epc}`;
    const now = read.lastSeen;
    const window = ctx.profile.dedupWindowMs;

    const previous = this.lastEmitted.get(key);
    if (previous !== undefined && now - previous < window) {
      return null;
    }

    this.lastEmitted.set(key, now);
    this.sweepIfNeeded(now, window);
    return read;
  }

  /**
   * Sin esta limpieza, el mapa crece sin límite durante un inventario
   * de 20 000 tags y termina agotando la memoria del mini-PC.
   */
  private sweepIfNeeded(now: number, window: number): void {
    if (now - this.lastSweep < 60_000) return;

    const cutoff = now - window * 10;
    for (const [key, ts] of this.lastEmitted) {
      if (ts < cutoff) this.lastEmitted.delete(key);
    }
    this.lastSweep = now;
  }
}
```

### Etapa [5] — Dirección de cruce (solo portales)

Es la etapa con más valor y más sutileza. Determina si un tag **entró** o **salió**.

```typescript
export class DirectionStage implements Stage {
  readonly name = 'direction';

  /** Historial reciente por EPC dentro de la ventana de tránsito. */
  private readonly transits = new Map<string, TransitTrace>();

  private static readonly WINDOW_MS = 3000;
  private static readonly MIN_SAMPLES = 3;

  process(read: RawTagRead, ctx: PipelineContext): ProcessedTagRead | null {
    if (ctx.deviceKind !== 'lector_fijo') {
      return { ...read, sessionRef: '' } as ProcessedTagRead;
    }

    const antenna = ctx.antennaConfig.get(read.antennaPort);
    const side = antenna?.side ?? 'desconocido';   // 'interior' | 'exterior'

    const trace = this.transits.get(read.epc) ?? { samples: [], started: read.firstSeen };
    trace.samples.push({ t: read.lastSeen, side, rssi: read.rssi });
    this.transits.set(read.epc, trace);

    // Aún no hay evidencia suficiente: se retiene sin emitir.
    if (trace.samples.length < DirectionStage.MIN_SAMPLES) return null;
    if (read.lastSeen - trace.started < 300) return null;

    const { direction, confidence } = this.classify(trace);
    this.transits.delete(read.epc);

    if (direction === 'indeterminado' && confidence < 0.5) return null;

    return { ...read, direction, confidence, sessionRef: '' } as ProcessedTagRead;
  }

  /**
   * Heurística de clasificación:
   *
   *  - Se calcula el centroide temporal ponderado por RSSI de cada lado.
   *  - Si el centroide del lado interior es ANTERIOR al del exterior,
   *    el tag se movió de dentro hacia fuera → salida.
   *  - La confianza sale de la separación temporal y del desequilibrio
   *    de potencia entre lados.
   *
   * Alternativa superior si el lector la soporta: usar phaseAngle o
   * dopplerHz, que dan dirección directamente sin heurística. Se prefiere
   * cuando está disponible.
   */
  private classify(trace: TransitTrace): { direction: Direction; confidence: number } {
    const inner = trace.samples.filter((s) => s.side === 'interior');
    const outer = trace.samples.filter((s) => s.side === 'exterior');

    if (inner.length === 0 || outer.length === 0) {
      return { direction: 'indeterminado', confidence: 0.3 };
    }

    const centroid = (arr: Sample[]) => {
      const w = arr.reduce((acc, s) => acc + Math.pow(10, s.rssi / 10), 0);
      return arr.reduce((acc, s) => acc + s.t * Math.pow(10, s.rssi / 10), 0) / w;
    };

    const ci = centroid(inner);
    const co = centroid(outer);
    const deltaMs = co - ci;                       // > 0 → interior primero → salida

    const confidence = Math.min(1, Math.abs(deltaMs) / 800);

    if (Math.abs(deltaMs) < 120) {
      return { direction: 'indeterminado', confidence: confidence * 0.5 };
    }

    return { direction: deltaMs > 0 ? 'salida' : 'entrada', confidence };
  }
}
```

> **Advertencia honesta**: esta heurística funciona razonablemente bien pero **no** al 100 %. Un cliente que se detiene en el umbral, da media vuelta y regresa produce trazas ambiguas. Por eso `portal_events` guarda toda la evidencia y la alarma exige `confidence >= 0.7` por defecto. La alternativa robusta es hardware con capacidad de localización (Zebra ATR7000), que cuesta el doble.

### Tres correcciones sobre el borrador de arriba (tarea 6.1)

El código de esta sección es el punto de partida. Al implementarlo aparecieron tres defectos que la versión de `traza-edge/src/pipeline/stages/DirectionStage.ts` corrige:

1. **La confianza ignoraba la potencia.** El texto dice que la confianza sale «de la separación temporal *y del desequilibrio de potencia entre lados*», pero el cálculo solo usaba el tiempo. Es justo el factor que falta el que distingue un cruce de un amago: quien se asoma y retrocede deja **una** lectura débil en la antena exterior, mientras que quien sale de verdad la deja pegada. Ahora `confidence = separación × equilibrio`, donde el equilibrio mide qué cuota de la potencia total reúne el lado de destino (referencia: 25 %).

2. **La normalización de 800 ms daba por supuesto un paso lento.** Con un lector a 200 lecturas/s el cruce entero dura 300 ms y ninguna salida real habría llegado nunca al 0.7 que exige la alarma. La separación se mide ahora contra la duración del propio rastro, así que un cruce limpio puntúa igual vaya la persona deprisa o despacio.

3. **El rastro se borraba aunque no se hubiera clasificado nada.** Con `MIN_SAMPLES = 3`, un cruce real interior-interior-interior-exterior se partía en trozos de tres muestras y ninguno llegaba a tener los dos lados: no se emitía jamás. El rastro solo se descarta ahora cuando ha servido para emitir un evento, con un TTL de 15 s y un tope de 64 muestras para que un tag olvidado en el umbral no haga crecer el mapa sin fin.

El precio de la corrección 1 es explícito: una salida real en la que la antena exterior lee flojo —el tag va al otro lado del cuerpo— se queda en `indeterminado` y no suena. Es un falso negativo aceptado a conciencia, porque P09 prioriza no chillar sin motivo: un portal con muchos falsos positivos acaba desconectado y entonces no detecta nada.

---

## 5. Adaptadores de lector

```typescript
// src/readers/ReaderAdapter.ts
export interface ReaderAdapter {
  readonly id: string;
  connect(): Promise<void>;
  disconnect(): Promise<void>;
  applyProfile(profile: ReadProfile): Promise<void>;
  startInventory(): Promise<void>;
  stopInventory(): Promise<void>;
  on(event: 'read', handler: (read: RawTagRead) => void): void;
  on(event: 'error', handler: (err: Error) => void): void;
  on(event: 'disconnected', handler: () => void): void;
}
```

### LLRP

LLRP (Low Level Reader Protocol, EPCglobal) es un protocolo binario sobre **TCP puerto 5084**. Estructura de mensaje:

```
 ┌─────────┬──────────┬──────────┬─────────────────────────┐
 │ Rsvd(3) │ Ver(3)   │ Type(10) │ Length (32 bits)        │  cabecera 10 bytes
 ├─────────┴──────────┴──────────┼─────────────────────────┤
 │ Message ID (32 bits)          │                         │
 ├───────────────────────────────┴─────────────────────────┤
 │ Parámetros TLV / TV                                      │
 └──────────────────────────────────────────────────────────┘
```

Secuencia de inicialización mínima:

```
→ SET_READER_CONFIG        (reset a fábrica; configurar antenas y potencia)
← SET_READER_CONFIG_RESPONSE
→ ADD_ROSPEC               (ROSpec: qué antenas, qué duración, qué reportar)
← ADD_ROSPEC_RESPONSE
→ ENABLE_ROSPEC
← ENABLE_ROSPEC_RESPONSE
→ START_ROSPEC
← START_ROSPEC_RESPONSE
← RO_ACCESS_REPORT         ◀── flujo continuo de lecturas
← RO_ACCESS_REPORT
   ...
→ STOP_ROSPEC / DELETE_ROSPEC
```

Parámetros del ROSpec que hay que configurar explícitamente (los valores por defecto casi nunca sirven):

| Parámetro | Valor TRAZA |
|---|---|
| `C1G2InventoryCommand.SessionID` | Del perfil de lectura (S0 para portal, S2 para inventario) |
| `C1G2SingulationControl.TagPopulation` | Estimación de tags en campo; determina Q inicial |
| `C1G2RFControl.ModeIndex` | Modo DRM si hay lectores próximos |
| `AntennaConfiguration.TransmitPower` | Índice de la tabla de potencia del lector, no dBm directos |
| `TagReportContentSelector` | Habilitar `EnableAntennaID`, `EnablePeakRSSI`, `EnableFirstSeenTimestamp`, `EnableLastSeenTimestamp`, `EnableTagSeenCount` |
| `ROReportTrigger` | `Upon_N_Tags_Or_End_Of_ROSpec` con N=50, o por temporizador de 250 ms |

```typescript
// src/readers/LlrpAdapter.ts (extracto)
import net from 'node:net';
import { EventEmitter } from 'node:events';

export class LlrpAdapter extends EventEmitter implements ReaderAdapter {
  private socket?: net.Socket;
  private buffer = Buffer.alloc(0);
  private reconnectDelay = 1000;

  constructor(
    public readonly id: string,
    private readonly host: string,
    private readonly port = 5084,
  ) { super(); }

  async connect(): Promise<void> {
    return new Promise((resolve, reject) => {
      this.socket = net.createConnection({ host: this.host, port: this.port });

      this.socket.on('connect', () => {
        this.reconnectDelay = 1000;
        resolve();
      });

      this.socket.on('data', (chunk) => this.onData(chunk));
      this.socket.on('error', (err) => this.emit('error', err));
      this.socket.on('close', () => {
        this.emit('disconnected');
        this.scheduleReconnect();
      });

      this.socket.setTimeout(30_000, () => this.socket?.destroy());
    });
  }

  /** Los mensajes LLRP llegan fragmentados; hay que reensamblar por longitud. */
  private onData(chunk: Buffer): void {
    this.buffer = Buffer.concat([this.buffer, chunk]);

    while (this.buffer.length >= 10) {
      const length = this.buffer.readUInt32BE(2);
      if (length < 10 || this.buffer.length < length) break;

      const message = this.buffer.subarray(0, length);
      this.buffer = this.buffer.subarray(length);
      this.handleMessage(message);
    }
  }

  /** Reconexión con retroceso exponencial y tope. */
  private scheduleReconnect(): void {
    setTimeout(() => {
      this.connect().catch(() => {});
      this.reconnectDelay = Math.min(this.reconnectDelay * 2, 60_000);
    }, this.reconnectDelay);
  }
}
```

> **Recomendación práctica**: implementar LLRP desde cero es un proyecto en sí mismo (la especificación tiene cientos de páginas). Evaluar librerías existentes de npm para LLRP, o usar el SDK del fabricante si expone una API de red más sencilla (muchos lectores modernos ofrecen REST + webhook o MQTT nativo, que es mucho más fácil de integrar). **Elegir un lector con MQTT o webhook nativo ahorra semanas de trabajo.**

---

## 6. Camino rápido del portal

El evento de portal **no pasa por la cola de ingesta normal**. Necesita < 800 ms extremo a extremo.

```
lector → pipeline (dirección) → MQTT `traza/{tienda}/portal` → suscriptor Laravel
                              ↘ zumbador local (GPIO / relé del lector)
```

```typescript
// src/index.ts (extracto)
pipeline.onProcessed(async (read) => {
  if (read.direction === 'salida' && read.confidence! >= 0.7) {
    // 1. Publicación inmediata para que el backend decida
    await mqtt.publish(`traza/${config.locationCode}/portal`, JSON.stringify({
      epc: read.epc,
      direction: read.direction,
      confidence: read.confidence,
      occurredAt: new Date(read.lastSeen).toISOString(),
      evidence: read.evidence,
    }), { qos: 1 });
  }

  // 2. Y siempre al buffer, para el registro histórico
  await buffer.enqueue(read);
});
```

Del lado de Laravel, un suscriptor persistente:

```php
// app/Console/Commands/ListenPortalEvents.php
public function handle(PortalEventService $service): int
{
    $client = new MqttClient(config('mqtt.host'), config('mqtt.port'), 'traza-portal-listener');
    $client->connect($this->connectionSettings());

    $client->subscribe('traza/+/portal', function (string $topic, string $payload) use ($service) {
        $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        // Camino crítico: una única consulta indexada
        $sold = DB::table('sale_lines')
            ->join('tags', 'tags.id', '=', 'sale_lines.tag_id')
            ->join('sale_transactions as st', 'st.id', '=', 'sale_lines.sale_transaction_id')
            ->where('tags.epc', $data['epc'])
            ->where('st.sold_at', '>=', now()->subSeconds(config('traza.portal.sale_grace_seconds')))
            ->exists();

        $service->record($data, wasSold: $sold);

        if (! $sold && $data['confidence'] >= config('traza.portal.min_confidence')) {
            PortalAlarmRaised::dispatch($data['epc'], $topic);
        }
    }, qos: 1);

    $client->loop(allowSleep: true);
    return self::SUCCESS;
}
```

> **Sobre el período de gracia**: `sale_grace_seconds = 120` significa que si la prenda se vendió en los últimos 2 minutos, cruzar el portal es normal. Demasiado corto → falsas alarmas con clientes que tardan en salir. Demasiado largo → un ladrón podría esperar tras una venta legítima. 120 s es un punto de partida razonable; ajustar observando la distribución real de tiempo entre pago y salida.

### Lo que cambia en la implementación (tareas 6.2 y 6.3)

El esquema del suscriptor es el correcto; la versión de `app/Console/Commands/ListenPortalEvents.php` añade lo que hace falta para que aguante en una tienda:

- **La decisión vive en `PortalEventService`, no en el callback.** El comando solo traduce el mensaje MQTT; el servicio es el mismo que usa el respaldo HTTP, así que las dos vías deciden igual y hay un único sitio que probar.
- **Un mensaje malformado no tumba el proceso.** Si cae, la tienda se queda sin antihurto y nadie se entera hasta que roban algo. JSON ilegible, mensaje sin `epc` o dispositivo desconocido se registran en el log y se descartan.
- **Reconexión automática indefinida.** La conexión de una tienda de Gamarra se cae varias veces al día y nadie va a reiniciar el proceso a mano. Ojo: `setMaxReconnectAttempts(0)` significa «no intentarlo nunca» en php-mqtt, no «infinito».
- **Señal de vida desde el bucle.** El proceso toca `/tmp/portal-listener.alive` cada 30 s desde el propio `loop`, que es lo que vigila el healthcheck de `infra/docker-compose.prod.yml`. Tocar el fichero desde fuera del bucle habría dado por sano un proceso vivo pero colgado.
- **El período de gracia y el estado del tag son comprobaciones distintas.** Una venta hecha en TRAZA ya deja el tag en `vendido`, así que la ventana de 120 s parece redundante — pero no lo es: cubre las ventas que llegan de un POS externo, que escribe la línea sin tocar el ciclo de vida del tag.
- **Respaldo HTTP.** `POST /api/v1/ingest/portal-event` (token de dispositivo) hace lo mismo por HTTP para las tiendas sin broker y para diagnosticar un portal recién instalado desde `curl`. Es más lento y no se compromete al presupuesto de 800 ms.

---

## 7. Buffer offline

```typescript
// src/buffer/SqliteBuffer.ts
import Database from 'better-sqlite3';

export class SqliteBuffer {
  private readonly db: Database.Database;

  constructor(path: string, private readonly maxRows = 2_000_000) {
    this.db = new Database(path);
    this.db.pragma('journal_mode = WAL');
    this.db.pragma('synchronous = NORMAL');   // durabilidad suficiente, mucho más rápido

    this.db.exec(`
      CREATE TABLE IF NOT EXISTS pending_reads (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        payload     TEXT    NOT NULL,
        created_at  INTEGER NOT NULL,
        attempts    INTEGER NOT NULL DEFAULT 0
      );
      CREATE INDEX IF NOT EXISTS pending_reads_created ON pending_reads (created_at);
    `);
  }

  enqueueMany(reads: ProcessedTagRead[]): void {
    const stmt = this.db.prepare(
      'INSERT INTO pending_reads (payload, created_at) VALUES (?, ?)'
    );
    const now = Date.now();

    // Una transacción para todo el lote: 100× más rápido que insertar una a una.
    this.db.transaction(() => {
      for (const r of reads) stmt.run(JSON.stringify(r), now);
    })();

    this.enforceLimit();
  }

  take(limit: number): { id: number; payload: ProcessedTagRead }[] {
    return this.db
      .prepare('SELECT id, payload FROM pending_reads ORDER BY id LIMIT ?')
      .all(limit)
      .map((r: any) => ({ id: r.id, payload: JSON.parse(r.payload) }));
  }

  ack(ids: number[]): void {
    const placeholders = ids.map(() => '?').join(',');
    this.db.prepare(`DELETE FROM pending_reads WHERE id IN (${placeholders})`).run(...ids);
  }

  depth(): number {
    return this.db.prepare('SELECT COUNT(*) AS n FROM pending_reads').get().n;
  }

  /**
   * Si el buffer supera el límite, se descartan las lecturas MÁS ANTIGUAS.
   * Decisión deliberada: en un corte prolongado, las lecturas recientes
   * reflejan mejor el estado actual del inventario. Se registra una alerta.
   */
  private enforceLimit(): void {
    const n = this.depth();
    if (n <= this.maxRows) return;

    this.db.prepare(`
      DELETE FROM pending_reads
      WHERE id IN (SELECT id FROM pending_reads ORDER BY id LIMIT ?)
    `).run(n - this.maxRows);

    console.warn(`[buffer] Límite superado: descartadas ${n - this.maxRows} lecturas antiguas.`);
  }
}
```

### Vaciado con retroceso exponencial

```typescript
export class Flusher {
  private delay = 1000;

  constructor(
    private readonly buffer: SqliteBuffer,
    private readonly api: ApiClient,
    private readonly batchSize = 500,
  ) {}

  start(): void {
    const tick = async () => {
      try {
        const items = this.buffer.take(this.batchSize);

        if (items.length > 0) {
          const batchId = crypto.randomUUID();
          await this.api.postReads(batchId, items.map((i) => i.payload));
          this.buffer.ack(items.map((i) => i.id));
          this.delay = 1000;
        } else {
          this.delay = 2000;
        }
      } catch (err) {
        // Retroceso exponencial hasta 1 minuto. No se hace ack: se reintenta.
        this.delay = Math.min(this.delay * 2, 60_000);
        console.error(`[flusher] Fallo de envío, reintento en ${this.delay} ms`, err);
      }
      setTimeout(tick, this.delay);
    };

    tick();
  }
}
```

---

## 8. Simulador de lector

**Entregable de primera clase.** Sin él, ningún desarrollador puede trabajar sin hardware.

```typescript
// src/readers/SimulatorAdapter.ts
export class SimulatorAdapter extends EventEmitter implements ReaderAdapter {
  private timer?: NodeJS.Timeout;
  private population: string[] = [];

  constructor(
    public readonly id: string,
    private readonly options: {
      populationSize: number;
      epcPrefix: string;
      readsPerSecond: number;
      /** Probabilidad de NO leer un tag presente. Simula el mundo real. */
      missRate: number;
      /** Probabilidad de emitir un EPC ajeno. Simula sobre-lectura. */
      strayRate: number;
      antennas: number;
      scenario: 'inventario' | 'portal' | 'recepcion';
    },
  ) {
    super();
    this.population = this.generatePopulation();
  }

  async startInventory(): Promise<void> {
    const intervalMs = 1000 / this.options.readsPerSecond;

    this.timer = setInterval(() => {
      // Tag ajeno ocasional: prueba el filtro de máscara
      if (Math.random() < this.options.strayRate) {
        this.emit('read', this.makeRead(this.randomForeignEpc(), true));
        return;
      }

      const epc = this.population[Math.floor(Math.random() * this.population.length)];

      // Fallo de lectura: prueba que el sistema tolera huecos
      if (Math.random() < this.options.missRate) return;

      this.emit('read', this.makeRead(epc, false));
    }, intervalMs);
  }

  private makeRead(epc: string, stray: boolean): RawTagRead {
    const now = Date.now();
    return {
      epc,
      antennaPort: 1 + Math.floor(Math.random() * this.options.antennas),
      // Los tags ajenos llegan con RSSI baja: es lo que los delata
      rssi: stray ? -75 + Math.random() * 8 : -58 + Math.random() * 20,
      firstSeen: now,
      lastSeen: now,
      readCount: 1 + Math.floor(Math.random() * 5),
      readerId: this.id,
    };
  }
}
```

Escenarios predefinidos para pruebas automatizadas:

| Escenario | Configuración | Qué valida |
|---|---|---|
| `inventario_limpio` | 5 000 tags, missRate 0.02, strayRate 0 | Camino feliz, rendimiento de ingesta |
| `inventario_dificil` | 5 000 tags, missRate 0.15, strayRate 0.05 | Umbral de `missed_cycles`, filtro de máscara |
| `portal_salida` | 3 tags cruzando, secuencia interior→exterior | Clasificación de dirección |
| `portal_dudoso` | Tag que se acerca y retrocede | Que NO se dispare la alarma |
| `vecino_ruidoso` | 90 % de lecturas con prefijo ajeno | Filtro de máscara |
| `red_caida` | API devuelve 503 durante 10 min | Buffer y vaciado con retroceso |
| `avalancha` | 8 000 lecturas/s durante 60 s | Contrapresión, límites de memoria |

---

## 9. Salud y observabilidad

```typescript
// src/health/Heartbeat.ts
export class Heartbeat {
  start(): void {
    setInterval(async () => {
      const payload = {
        device_code: config.deviceCode,
        cpu_percent: await getCpuPercent(),
        temperature_c: await getTemperature(),
        reads_last_min: this.pipeline.snapshot().passed ?? 0,
        buffer_depth: this.buffer.depth(),
        readers: this.readerManager.status(),   // conectado/desconectado por lector
        uptime_s: process.uptime(),
        version: pkg.version,
      };

      try {
        await this.api.postHeartbeat(payload);
      } catch {
        // Un latido perdido no es crítico; el servidor detectará la ausencia.
      }
    }, 30_000);
  }
}
```

Métricas expuestas en `/metrics` (formato Prometheus):

```
traza_edge_reads_total{reader="FX9600-01",stage="passed"}
traza_edge_reads_dropped_total{reader="FX9600-01",stage="epc_mask"}
traza_edge_reads_dropped_total{reader="FX9600-01",stage="rssi_threshold"}
traza_edge_reads_dropped_total{reader="FX9600-01",stage="dedupe"}
traza_edge_buffer_depth
traza_edge_flush_duration_seconds
traza_edge_reader_connected{reader="FX9600-01"}
traza_edge_portal_events_total{direction="salida"}
```

> **Métrica más útil del sistema**: `reads_dropped_total{stage="epc_mask"}`. Si sube de repente, o el vecino instaló RFID, o alguien metió mercadería no tarada. Ambas cosas quieres saberlas.

---

## 10. Apagado ordenado

```typescript
// src/index.ts
const shutdown = async (signal: string) => {
  console.log(`[edge] Recibida señal ${signal}, cerrando ordenadamente…`);

  // 1. Dejar de aceptar lecturas nuevas
  await readerManager.stopAll();

  // 2. Vaciar lo que quede en memoria hacia SQLite (no se pierde nada)
  await pipeline.drain();

  // 3. Intentar un último envío, con límite de tiempo
  await Promise.race([
    flusher.flushOnce(),
    new Promise((r) => setTimeout(r, 5000)),
  ]);

  // 4. Cerrar conexiones
  await mqtt.end();
  buffer.close();

  process.exit(0);
};

process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));
```

> El paso 2 es la razón por la que el buffer SQLite existe aunque haya red. Un reinicio del contenedor no debe perder las lecturas en vuelo.
