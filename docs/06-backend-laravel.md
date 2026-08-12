# 06 — Backend (Laravel 11)

---

## 1. Estructura de directorios

```
app/
├── Domain/                         # Lógica de negocio pura. Sin Eloquent, sin HTTP.
│   ├── Tagging/
│   │   ├── Epc/
│   │   │   ├── EpcCodec.php                 (interfaz)
│   │   │   ├── Sgtin96Codec.php
│   │   │   ├── Gid96Codec.php
│   │   │   └── EpcCodecFactory.php
│   │   ├── TagStateMachine.php
│   │   └── Exceptions/InvalidTransition.php
│   ├── Inventory/
│   │   ├── CycleReconciler.php
│   │   └── ReconciliationResult.php
│   ├── Movements/
│   │   ├── MovementIntent.php               (objeto de valor)
│   │   └── MovementRules.php
│   └── Contracts/
│       ├── TagRepository.php
│       └── MovementRepository.php
│
├── Models/                         # Eloquent — detalle de persistencia
│   ├── Tag.php  Product.php  ProductVariant.php  StockMovement.php
│   ├── InventoryCycle.php  Device.php  Location.php  Zone.php ...
│
├── Services/                       # Orquestación de casos de uso
│   ├── StockMovementService.php    ★ único punto autorizado para mutar stock
│   ├── TagCommissioningService.php
│   ├── InventoryCycleService.php
│   ├── ReadIngestionService.php
│   ├── PortalEventService.php
│   ├── LabelPrintingService.php
│   └── AlertService.php
│
├── Http/
│   ├── Controllers/Api/V1/
│   │   ├── IngestController.php
│   │   ├── TagController.php
│   │   ├── InventoryCycleController.php
│   │   ├── StockController.php
│   │   ├── DeviceController.php
│   │   └── ...
│   ├── Requests/                   # Validación
│   ├── Resources/                  # Serialización de respuestas
│   └── Middleware/
│       ├── AuthenticateDevice.php
│       └── EnsureOrganizationScope.php
│
├── Jobs/
│   ├── ProcessReadBatch.php
│   ├── ReconcileInventoryCycle.php
│   ├── EvaluatePortalEvent.php
│   ├── RefreshStockSnapshots.php
│   └── RotateReadPartitions.php
│
├── Events/  Listeners/  Policies/  Console/Commands/
└── Support/
    └── EpcMask.php
```

---

## 2. `StockMovementService` — el corazón

> **Regla arquitectónica inviolable**: ninguna otra clase escribe en `stock_movements` ni modifica `tags.state`, `tags.current_location_id` o `tags.current_zone_id`. Si necesitas mover stock, pasas por aquí. Sin excepciones.

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Movements\MovementIntent;
use App\Domain\Tagging\TagStateMachine;
use App\Domain\Tagging\Exceptions\InvalidTransition;
use App\Models\StockMovement;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

final class StockMovementService
{
    public function __construct(
        private readonly TagStateMachine $stateMachine,
    ) {}

    /**
     * Aplica un movimiento sobre un tag concreto.
     * Escribe el movimiento y actualiza la proyección en una sola transacción.
     */
    public function apply(MovementIntent $intent): StockMovement
    {
        return DB::transaction(function () use ($intent) {
            // Bloqueo pesimista: dos operarios pueden escanear la misma prenda
            // simultáneamente (uno en caja, otro en inventario).
            $tag = Tag::query()
                ->whereKey($intent->tagId)
                ->lockForUpdate()
                ->firstOrFail();

            $stateBefore = $tag->state;
            $stateAfter  = $intent->targetState ?? $this->stateMachine->resolve($stateBefore, $intent->type);

            if (! $this->stateMachine->canTransition($stateBefore, $stateAfter)) {
                throw new InvalidTransition(
                    "Transición no permitida: {$stateBefore->value} → {$stateAfter->value} "
                    . "(movimiento: {$intent->type->value}, EPC: {$tag->epc})"
                );
            }

            $movement = StockMovement::create([
                'organization_id'    => $tag->organization_id,
                'tag_id'             => $tag->id,
                'product_variant_id' => $tag->product_variant_id ?? $intent->productVariantId,
                'movement_type'      => $intent->type,
                'quantity'           => $intent->quantity,
                'from_location_id'   => $tag->current_location_id,
                'from_zone_id'       => $tag->current_zone_id,
                'to_location_id'     => $intent->toLocationId,
                'to_zone_id'         => $intent->toZoneId,
                'state_before'       => $stateBefore,
                'state_after'        => $stateAfter,
                'user_id'            => $intent->userId,
                'device_id'          => $intent->deviceId,
                'reference_type'     => $intent->referenceType,
                'reference_id'       => $intent->referenceId,
                'reason'             => $intent->reason,
                'unit_cost'          => $intent->unitCost,
                'metadata'           => $intent->metadata,
                'occurred_at'        => $intent->occurredAt ?? now(),
            ]);

            // Proyección síncrona (ver docs/05, §2.1)
            $tag->fill([
                'state'               => $stateAfter,
                'current_location_id' => $intent->toLocationId ?? $tag->current_location_id,
                'current_zone_id'     => $intent->toZoneId,
                'last_seen_at'        => $intent->occurredAt ?? now(),
            ]);

            if ($stateAfter->value === 'vendido') {
                $tag->sold_at = $intent->occurredAt ?? now();
            }
            if ($stateAfter->value === 'en_stock') {
                $tag->missed_cycles = 0;
            }

            $tag->save();

            return $movement;
        }, attempts: 3);
    }

    /**
     * Aplica el mismo movimiento a muchos tags. Usado en recepción,
     * transferencias y cierre de ciclo, donde N puede ser de miles.
     *
     * @param  array<int>  $tagIds
     * @return int  número de movimientos aplicados
     */
    public function applyBulk(array $tagIds, MovementIntent $template): int
    {
        $applied = 0;

        // Se procesa en trozos para no mantener miles de locks de fila abiertos
        // durante toda la operación.
        foreach (array_chunk($tagIds, 500) as $chunk) {
            DB::transaction(function () use ($chunk, $template, &$applied) {
                foreach ($chunk as $tagId) {
                    $this->apply($template->forTag($tagId));
                    $applied++;
                }
            });
        }

        return $applied;
    }
}
```

### `MovementIntent` (objeto de valor inmutable)

```php
<?php

namespace App\Domain\Movements;

use App\Enums\MovementType;
use App\Enums\TagState;
use Carbon\CarbonInterface;

final readonly class MovementIntent
{
    public function __construct(
        public ?int              $tagId = null,
        public ?int              $productVariantId = null,
        public MovementType      $type = MovementType::CambioZona,
        public int               $quantity = 1,
        public ?int              $toLocationId = null,
        public ?int              $toZoneId = null,
        public ?TagState         $targetState = null,
        public ?int              $userId = null,
        public ?int              $deviceId = null,
        public ?string           $referenceType = null,
        public ?int              $referenceId = null,
        public ?string           $reason = null,
        public ?float            $unitCost = null,
        public array             $metadata = [],
        public ?CarbonInterface  $occurredAt = null,
    ) {}

    public function forTag(int $tagId): self
    {
        return new self(
            tagId: $tagId,
            productVariantId: $this->productVariantId,
            type: $this->type,
            quantity: $this->quantity,
            toLocationId: $this->toLocationId,
            toZoneId: $this->toZoneId,
            targetState: $this->targetState,
            userId: $this->userId,
            deviceId: $this->deviceId,
            referenceType: $this->referenceType,
            referenceId: $this->referenceId,
            reason: $this->reason,
            unitCost: $this->unitCost,
            metadata: $this->metadata,
            occurredAt: $this->occurredAt,
        );
    }
}
```

---

## 3. Máquina de estados

```php
<?php

namespace App\Domain\Tagging;

use App\Enums\MovementType;
use App\Enums\TagState;

final class TagStateMachine
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'creado'      => ['codificado', 'anulado'],
        'codificado'  => ['en_stock', 'anulado'],
        'en_stock'    => ['vendido', 'en_transito', 'no_visto', 'danado', 'baja', 'en_stock'],
        'en_transito' => ['en_stock', 'perdido'],
        'no_visto'    => ['en_stock', 'perdido', 'vendido'],
        'perdido'     => ['en_stock'],   // reaparición: genera alerta
        'vendido'     => ['en_stock'],   // devolución de cliente
        'danado'      => ['baja'],
        'baja'        => [],
        'anulado'     => [],
    ];

    /** Estado resultante por defecto según el tipo de movimiento. */
    private const DEFAULT_TARGET = [
        'tarado'             => 'en_stock',
        'recepcion'          => 'en_stock',
        'venta'              => 'vendido',
        'devolucion_cliente' => 'en_stock',
        'devolucion_prov'    => 'baja',
        'transferencia_out'  => 'en_transito',
        'transferencia_in'   => 'en_stock',
        'ajuste_positivo'    => 'en_stock',
        'ajuste_negativo'    => 'no_visto',
        'merma'              => 'perdido',
        'dano'               => 'danado',
        'cambio_zona'        => 'en_stock',
        'reetiquetado'       => 'baja',
        'anulacion'          => 'anulado',
    ];

    public function canTransition(TagState $from, TagState $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], strict: true);
    }

    public function resolve(TagState $from, MovementType $type): TagState
    {
        return TagState::from(self::DEFAULT_TARGET[$type->value]
            ?? throw new \LogicException("Sin estado destino para el movimiento {$type->value}."));
    }

    /** @return list<TagState> */
    public function allowedFrom(TagState $from): array
    {
        return array_map(TagState::from(...), self::TRANSITIONS[$from->value] ?? []);
    }
}
```

---

## 4. Ingesta de lecturas

### 4.1 Endpoint

`POST /api/v1/ingest/reads` — autenticado con **token de dispositivo**, no de usuario.

```json
{
  "device_code": "EDGE-LIM01",
  "batch_id": "0198f2b4-...",
  "session_ref": "0198f2b5-...",
  "inventory_cycle_id": 42,
  "reads": [
    {
      "epc": "3035D919080C0E403B9ACA2A",
      "tid": "E2801190200070C8A1B2C3D4",
      "antenna": 2,
      "rssi": -52.4,
      "read_at": "2026-08-10T14:22:11.418Z",
      "read_count": 7
    }
  ]
}
```

Respuesta `202 Accepted`:

```json
{ "accepted": 500, "rejected": 3, "batch_id": "0198f2b4-...", "queued_job": "ProcessReadBatch" }
```

### 4.2 Controlador

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\IngestReadsRequest;
use App\Jobs\ProcessReadBatch;
use App\Services\ReadIngestionService;
use Illuminate\Http\JsonResponse;

final class IngestController
{
    public function __construct(private readonly ReadIngestionService $ingestion) {}

    public function store(IngestReadsRequest $request): JsonResponse
    {
        $device = $request->device();          // resuelto por AuthenticateDevice

        // Idempotencia: si el borde reintenta por timeout de red, no duplicamos.
        if ($this->ingestion->batchAlreadyProcessed($request->string('batch_id'))) {
            return response()->json(['status' => 'duplicate', 'accepted' => 0], 200);
        }

        $result = $this->ingestion->ingest(
            device: $device,
            batchId: $request->string('batch_id')->toString(),
            reads: $request->validated('reads'),
            sessionRef: $request->input('session_ref'),
            cycleId: $request->integer('inventory_cycle_id') ?: null,
        );

        ProcessReadBatch::dispatch($result->batchId, $device->id)
            ->onQueue('reads');

        return response()->json([
            'accepted'  => $result->accepted,
            'rejected'  => $result->rejected,
            'batch_id'  => $result->batchId,
        ], 202);
    }
}
```

### 4.3 Servicio de ingesta

```php
final class ReadIngestionService
{
    public function ingest(Device $device, string $batchId, array $reads, ?string $sessionRef, ?int $cycleId): IngestResult
    {
        $mask     = EpcMask::forOrganization($device->organization_id);
        $accepted = [];
        $rejected = 0;

        foreach ($reads as $r) {
            $epc = strtoupper($r['epc']);

            // Filtro [1] del pipeline. El borde ya filtró, pero se repite aquí:
            // el servidor nunca confía en la validación del cliente.
            if (! $mask->matches($epc)) {
                $rejected++;
                continue;
            }

            $accepted[] = [
                'read_at'            => $r['read_at'],
                'epc'                => $epc,
                'tid'                => isset($r['tid']) ? strtoupper($r['tid']) : null,
                'device_id'          => $device->id,
                'antenna_port'       => $r['antenna'] ?? null,
                'location_id'        => $device->location_id,
                'zone_id'            => $this->zoneForAntenna($device, $r['antenna'] ?? null),
                'rssi'               => $r['rssi'] ?? null,
                'read_count'         => $r['read_count'] ?? 1,
                'session_ref'        => $sessionRef,
                'inventory_cycle_id' => $cycleId,
                'ingested_at'        => now(),
            ];
        }

        // Inserción masiva. 500 filas por sentencia es el punto dulce
        // entre número de round-trips y tamaño de sentencia.
        foreach (array_chunk($accepted, 500) as $chunk) {
            DB::table('tag_reads')->insert($chunk);
        }

        Cache::put("ingest:batch:{$batchId}", true, now()->addHours(6));

        return new IngestResult($batchId, count($accepted), $rejected);
    }
}
```

> **Por qué `DB::table()->insert()` y no Eloquent**: crear 500 modelos Eloquent para insertarlos es un desperdicio de memoria y CPU de un orden de magnitud. `tag_reads` no tiene comportamiento de dominio; es un registro. No merece un modelo.

### 4.4 Job de procesamiento

```php
final class ProcessReadBatch implements ShouldQueue
{
    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly string $batchId,
        public readonly int    $deviceId,
    ) {}

    public function handle(
        TagResolver $resolver,
        InventoryCycleService $cycles,
        PortalEventService $portals,
        AlertService $alerts,
    ): void {
        $device = Device::findOrFail($this->deviceId);

        $reads = DB::table('tag_reads')
            ->where('device_id', $this->deviceId)
            ->where('ingested_at', '>=', now()->subMinutes(10))
            ->orderBy('read_at')
            ->get();

        $grouped = $reads->groupBy('epc');

        foreach ($grouped as $epc => $epcReads) {
            $tag = $resolver->find($device->organization_id, (string) $epc);

            if ($tag === null) {
                $resolver->recordUnknown((string) $epc, $device);
                continue;
            }

            // Detección de clonación: mismo EPC, distinto TID
            $tids = $epcReads->pluck('tid')->filter()->unique();
            if ($tag->tid && $tids->isNotEmpty() && ! $tids->contains($tag->tid)) {
                $alerts->raise(AlertKind::TidDiscrepante, $tag, $device, [
                    'tid_registrado' => $tag->tid,
                    'tid_leidos'     => $tids->values()->all(),
                ]);
            }

            match ($device->kind) {
                DeviceKind::Handheld   => $cycles->registerScan($tag, $epcReads, $device),
                DeviceKind::LectorFijo => $portals->evaluate($tag, $epcReads, $device),
                default                => null,
            };
        }
    }
}
```

---

## 5. Reconciliación de ciclo de inventario

```php
final class CycleReconciler
{
    public function reconcile(InventoryCycle $cycle): ReconciliationResult
    {
        return DB::transaction(function () use ($cycle) {
            // Las tres poblaciones se calculan en SQL, no en PHP.
            // Con 20 000 tags, traerlos a memoria y comparar arrays es
            // órdenes de magnitud más lento y consume cientos de MB.

            $found = DB::table('inventory_cycle_expected as e')
                ->join('inventory_cycle_scans as s', function ($j) use ($cycle) {
                    $j->on('s.tag_id', '=', 'e.tag_id')
                      ->where('s.inventory_cycle_id', '=', $cycle->id);
                })
                ->where('e.inventory_cycle_id', $cycle->id)
                ->count();

            $missingIds = DB::table('inventory_cycle_expected as e')
                ->where('e.inventory_cycle_id', $cycle->id)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('inventory_cycle_scans as s')
                    ->whereColumn('s.tag_id', 'e.tag_id')
                    ->where('s.inventory_cycle_id', $cycle->id))
                ->pluck('e.tag_id');

            $unexpectedIds = DB::table('inventory_cycle_scans as s')
                ->where('s.inventory_cycle_id', $cycle->id)
                ->whereNotNull('s.tag_id')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('inventory_cycle_expected as e')
                    ->whereColumn('e.tag_id', 's.tag_id')
                    ->where('e.inventory_cycle_id', $cycle->id))
                ->pluck('s.tag_id');

            $this->applyMissing($cycle, $missingIds);
            $this->applyUnexpected($cycle, $unexpectedIds);
            $this->writeResults($cycle);

            $expected = $cycle->expected_count ?? 0;

            $cycle->update([
                'status'           => CycleStatus::Cerrado,
                'closed_at'        => now(),
                'found_count'      => $found,
                'missing_count'    => $missingIds->count(),
                'unexpected_count' => $unexpectedIds->count(),
                'counted_count'    => $found + $unexpectedIds->count(),
                'accuracy_pct'     => $expected > 0 ? round(100 * $found / $expected, 3) : null,
            ]);

            return new ReconciliationResult($found, $missingIds->count(), $unexpectedIds->count());
        });
    }

    /**
     * Los faltantes NO se dan por perdidos de inmediato. Se incrementa
     * missed_cycles; sólo tras N ciclos consecutivos pasan a 'perdido'.
     * Un solo ciclo con un fallo de lectura no puede borrar stock real.
     */
    private function applyMissing(InventoryCycle $cycle, Collection $tagIds): void
    {
        $threshold = config('traza.inventory.missing_cycles_threshold', 2);

        Tag::whereIn('id', $tagIds)->increment('missed_cycles');

        $toLose = Tag::whereIn('id', $tagIds)
            ->where('missed_cycles', '>=', $threshold)
            ->where('state', '!=', TagState::Perdido)
            ->pluck('id');

        app(StockMovementService::class)->applyBulk($toLose->all(), new MovementIntent(
            type: MovementType::Merma,
            toLocationId: $cycle->location_id,
            referenceType: 'inventory_cycle',
            referenceId: $cycle->id,
            reason: "No detectado en {$threshold} ciclos consecutivos",
        ));

        // Los que aún no llegan al umbral pasan a 'no_visto'
        $notSeen = Tag::whereIn('id', $tagIds)
            ->where('missed_cycles', '<', $threshold)
            ->where('state', TagState::EnStock)
            ->pluck('id');

        app(StockMovementService::class)->applyBulk($notSeen->all(), new MovementIntent(
            type: MovementType::AjusteNegativo,
            toLocationId: $cycle->location_id,
            referenceType: 'inventory_cycle',
            referenceId: $cycle->id,
            reason: 'No detectado en el ciclo',
        ));
    }
}
```

> **La decisión de negocio más importante de todo el backend**: el umbral `missing_cycles_threshold`. Con 1, cualquier fallo de lectura borra stock que existe. Con 5, la merma se detecta demasiado tarde. **Valor recomendado: 2**, revisable tras tres meses de datos reales.

---

## 6. API REST — superficie completa

### Autenticación

| Consumidor | Mecanismo |
|---|---|
| Web (React) | Laravel Sanctum, cookie de sesión SPA |
| Handheld Android | Token personal Sanctum por usuario + `X-Device-Code` |
| `traza-edge` | Token de dispositivo (hash en `devices.api_token_hash`), rotable |

### Endpoints

```
# Ingesta (token de dispositivo)
POST   /api/v1/ingest/reads                 Lote de lecturas
POST   /api/v1/ingest/heartbeat             Latido de salud del dispositivo
POST   /api/v1/ingest/portal-event          Evento de tránsito prioritario

# Catálogo
GET    /api/v1/products                     ?search= &category= &page=
POST   /api/v1/products
GET    /api/v1/products/{id}
PUT    /api/v1/products/{id}
GET    /api/v1/variants                     ?sku= &barcode=
GET    /api/v1/variants/{id}/stock          Stock por ubicación

# Tags
GET    /api/v1/tags                         ?epc= &state= &location=
GET    /api/v1/tags/{epc}                   Ficha completa
GET    /api/v1/tags/{epc}/history           Trazabilidad (stock_movements)
POST   /api/v1/tags/commission              Tarado: EPC → variante
POST   /api/v1/tags/bulk-commission         Tarado masivo
POST   /api/v1/tags/{epc}/replace           Sustitución de tag
POST   /api/v1/tag-batches                  Reservar rango + generar ZPL
GET    /api/v1/tag-batches/{id}/zpl         Descargar el trabajo de impresión

# Stock
GET    /api/v1/stock                        ?location= &zone= &variant=
GET    /api/v1/stock/valuation
GET    /api/v1/stock/aging
GET    /api/v1/stock/replenishment          Alerta de reposición de sala

# Inventario
GET    /api/v1/inventory-cycles
POST   /api/v1/inventory-cycles             Crear (congela `expected`)
GET    /api/v1/inventory-cycles/{id}
POST   /api/v1/inventory-cycles/{id}/start
POST   /api/v1/inventory-cycles/{id}/pause
POST   /api/v1/inventory-cycles/{id}/scans  Lote de EPC desde el handheld
POST   /api/v1/inventory-cycles/{id}/close  Dispara la reconciliación
GET    /api/v1/inventory-cycles/{id}/report
GET    /api/v1/inventory-cycles/{id}/zone-performance

# Movimientos
GET    /api/v1/movements                    ?tag= &type= &from= &to=
POST   /api/v1/movements/transfer
POST   /api/v1/movements/zone-change

# Recepción
GET    /api/v1/receiving-orders
POST   /api/v1/receiving-orders
POST   /api/v1/receiving-orders/{id}/receive

# Ventas
POST   /api/v1/sales                        Registro de venta con EPC
POST   /api/v1/sales/{id}/return

# Dispositivos
GET    /api/v1/devices
POST   /api/v1/devices
GET    /api/v1/devices/health
PUT    /api/v1/devices/{id}/read-profile
POST   /api/v1/devices/{id}/rotate-token

# Alertas
GET    /api/v1/alerts                       ?status=abierta
POST   /api/v1/alerts/{id}/acknowledge
POST   /api/v1/alerts/{id}/resolve
```

### Convenciones de la API

| Aspecto | Convención |
|---|---|
| Versionado | En la ruta: `/api/v1/`. Se mantiene v1 al menos 12 meses tras publicar v2 |
| Paginación | `?page=` + `?per_page=` (máx. 200), respuesta con `meta.total` |
| Errores | RFC 7807 (`application/problem+json`) |
| Fechas | ISO-8601 con zona, siempre UTC en el cuerpo |
| Idempotencia | Cabecera `Idempotency-Key` en todos los `POST` que mutan stock |
| Filtros | Query string plana; sin lenguajes de filtro complejos |
| Límite de tasa | 300 req/min por usuario; 2 000 req/min por dispositivo de borde |

---

## 7. Colas (Horizon)

| Cola | Trabajos | Prioridad | Workers |
|---|---|---|---|
| `portal` | `EvaluatePortalEvent` | **Máxima** | 4 |
| `reads` | `ProcessReadBatch` | Alta | 6 |
| `inventory` | `ReconcileInventoryCycle` | Media | 2 |
| `default` | Notificaciones, correo | Baja | 2 |
| `maintenance` | `RotateReadPartitions`, `RefreshStockSnapshots` | Baja | 1 |

```php
// config/horizon.php (extracto)
'environments' => [
    'production' => [
        'portal' => [
            'connection' => 'redis', 'queue' => ['portal'],
            'balance' => 'simple', 'processes' => 4,
            'tries' => 5, 'timeout' => 15,
        ],
        'reads' => [
            'connection' => 'redis', 'queue' => ['reads'],
            'balance' => 'auto', 'minProcesses' => 2, 'maxProcesses' => 10,
            'tries' => 3, 'timeout' => 120,
        ],
        'inventory' => [
            'connection' => 'redis', 'queue' => ['inventory'],
            'balance' => 'simple', 'processes' => 2,
            'tries' => 2, 'timeout' => 600,
        ],
    ],
],
```

---

## 8. Eventos de dominio y difusión

```php
// app/Events/InventoryCycleProgressed.php
final class InventoryCycleProgressed implements ShouldBroadcast
{
    public function __construct(
        public readonly int $cycleId,
        public readonly int $scanned,
        public readonly int $expected,
        public readonly ?int $zoneId = null,
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("inventory-cycle.{$this->cycleId}");
    }

    public function broadcastWith(): array
    {
        return [
            'scanned'  => $this->scanned,
            'expected' => $this->expected,
            'progress' => $this->expected > 0
                ? round(100 * $this->scanned / $this->expected, 1)
                : 0,
            'zone_id'  => $this->zoneId,
        ];
    }
}
```

Eventos difundidos:

| Evento | Canal | Consumidor |
|---|---|---|
| `InventoryCycleProgressed` | `inventory-cycle.{id}` | Barra de progreso en web y handheld |
| `PortalAlarmRaised` | `location.{id}.alerts` | Panel de tienda, señal sonora |
| `StockChanged` | `location.{id}.stock` | Tablero en vivo |
| `DeviceHealthDegraded` | `org.{id}.devices` | Panel de operaciones |

---

## 9. Configuración (`config/traza.php`)

```php
return [
    'epc' => [
        'scheme'         => env('TRAZA_EPC_SCHEME', 'sgtin-96'),
        'company_prefix' => env('TRAZA_GS1_COMPANY_PREFIX'),
        'filter_value'   => (int) env('TRAZA_EPC_FILTER', 1),
        'mask'           => env('TRAZA_EPC_MASK'),      // prefijo hex propio
        'test_prefix'    => env('TRAZA_EPC_TEST_PREFIX', 'FFFF'),
    ],

    'inventory' => [
        'missing_cycles_threshold' => (int) env('TRAZA_MISSING_THRESHOLD', 2),
        'auto_close_after_minutes' => (int) env('TRAZA_CYCLE_AUTOCLOSE', 240),
        'min_accuracy_alert_pct'   => (float) env('TRAZA_MIN_ACCURACY', 95.0),
    ],

    'portal' => [
        'sale_grace_seconds'   => (int) env('TRAZA_PORTAL_GRACE', 120),
        'min_confidence'       => (float) env('TRAZA_PORTAL_CONFIDENCE', 0.7),
        'alarm_enabled'        => (bool) env('TRAZA_PORTAL_ALARM', true),
        'ignore_unknown_epcs'  => (bool) env('TRAZA_PORTAL_IGNORE_UNKNOWN', true),
    ],

    'ingest' => [
        'max_batch_size'       => (int) env('TRAZA_MAX_BATCH', 1000),
        'idempotency_ttl_hours'=> 6,
    ],

    'retention' => [
        'tag_reads_months'     => (int) env('TRAZA_READS_RETENTION', 3),
        'health_beats_days'    => 30,
    ],

    'ops_email' => env('TRAZA_OPS_EMAIL'),
];
```

---

## 10. Pruebas del backend

| Nivel | Herramienta | Qué se prueba |
|---|---|---|
| Unitarias | Pest | Codecs EPC, máquina de estados, `EpcMask`, reglas de reconciliación |
| Integración | Pest + base de datos real | `StockMovementService`, reconciliación, ingesta |
| API | Pest HTTP | Contratos de endpoints, autenticación, idempotencia |
| Carga | k6 | Ingesta a 5 000 lecturas/s, latencia de portal |

### Pruebas obligatorias antes de cualquier despliegue

```php
it('nunca permite una transición de estado ilegal', function (TagState $from, TagState $to) {
    $machine = new TagStateMachine();
    if (! $machine->canTransition($from, $to)) {
        expect(fn () => app(StockMovementService::class)->apply(
            new MovementIntent(tagId: $this->tag->id, targetState: $to)
        ))->toThrow(InvalidTransition::class);
    }
})->with('todas_las_combinaciones_de_estado');

it('mantiene la proyección sincronizada con los movimientos', function () {
    // 1 000 movimientos aleatorios sobre 100 tags
    $this->simulateRandomMovements(tags: 100, movements: 1000);

    $proyectado   = Tag::where('state', TagState::EnStock)
        ->where('current_location_id', $this->location->id)->count();
    $reconstruido = DB::select('SELECT SUM(quantity) AS q FROM stock_as_of(?, now())',
        [$this->location->id])[0]->q ?? 0;

    expect($proyectado)->toBe((int) $reconstruido);
});

it('rechaza EPC que no casan con la máscara de la organización', function () {
    $response = $this->withDeviceToken()->postJson('/api/v1/ingest/reads', [
        'device_code' => 'EDGE-TEST',
        'batch_id'    => Str::uuid()->toString(),
        'reads'       => [['epc' => 'AAAAAAAAAAAAAAAAAAAAAAAA', 'read_at' => now()->toIso8601String()]],
    ]);

    $response->assertAccepted()->assertJsonPath('rejected', 1);
    expect(DB::table('tag_reads')->count())->toBe(0);
});

it('es idempotente ante reenvío del mismo lote', function () {
    $payload = $this->makeBatch(reads: 100);

    $this->withDeviceToken()->postJson('/api/v1/ingest/reads', $payload)->assertAccepted();
    $this->withDeviceToken()->postJson('/api/v1/ingest/reads', $payload)->assertOk();

    expect(DB::table('tag_reads')->count())->toBe(100);
});
```
