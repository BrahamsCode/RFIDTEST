<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Movements\MovementIntent;
use App\Domain\Tagging\Epc\EpcCodec;
use App\Domain\Tagging\Epc\EpcCodecFactory;
use App\Domain\Tagging\TagStateMachine;
use App\Enums\MovementType;
use App\Enums\TagState;
use App\Models\Tag;
use App\Services\StockMovementService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tags de desarrollo. Equivale a las secciones 8 y 9 de `sql/seeds.sql`.
 *
 * Dos diferencias con la versión SQL, y las dos son correcciones:
 *
 * 1. **Cada tag recibe un movimiento por cada estado en el que acaba.** La
 *    versión SQL solo emite `tarado` y `venta`, así que un tag en `no_visto`
 *    o `perdido` queda con un último movimiento que dice `en_stock`.
 *    `stock_as_of()` lo cuenta como existencia y la proyección no, de modo
 *    que el control de integridad de `docs/05` §6 —el que "no debe
 *    silenciarse nunca"— devuelve filas con los datos de semilla recién
 *    cargados. Comprobado: 12 filas y 51 unidades de desajuste.
 *
 * 2. **La venta nunca es anterior al tarado.** La versión SQL sortea
 *    `commissioned_at` y `sold_at` de forma independiente sobre 200 y 60
 *    días, así que en 28 de cada 1 038 tags la prenda se vendía antes de
 *    existir. `stock_as_of()` ordena por fecha, así que el último movimiento
 *    pasaba a ser el tarado y esos tags también descuadraban.
 *
 * Además el EPC lo compone el codec real y no una concatenación de hex, así
 * que los datos de desarrollo ejercitan el mismo camino que producción.
 */
final class TagSeeder extends Seeder
{
    /** Semilla fija: dos ejecuciones dan los mismos datos y los fallos se reproducen. */
    private const RANDOM_SEED = 20260814;

    /**
     * 60..140 por variante y doce variantes dan del orden de 1 200 tags. El
     * criterio de la tarea 1.6 pide ≥ 1 000, y el rango de 40..120 de
     * `sql/seeds.sql` se queda corto de media: en la ejecución de referencia
     * salían 928.
     */
    private const MIN_PER_VARIANT = 60;

    private const MAX_PER_VARIANT = 140;

    public function __construct(
        private readonly StockMovementService $movements = new StockMovementService(
            new TagStateMachine,
        ),
    ) {}

    public function run(): void
    {
        mt_srand(self::RANDOM_SEED);

        $organization = DB::table('organizations')->orderBy('id')->first();
        $tienda = DB::table('locations')->where('code', 'LIM-01')->value('id');
        $zones = DB::table('zones')->where('location_id', $tienda)
            ->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();

        $codec = app(EpcCodecFactory::class)->for('sgtin-96');
        $prefix = (string) $organization->gs1_company_prefix;

        $variants = DB::table('product_variants')
            ->whereNotNull('item_reference')
            ->orderBy('id')
            ->get(['id', 'item_reference', 'cost_price']);

        $created = 0;

        foreach ($variants as $variant) {
            $created += $this->seedVariant(
                variantId: (int) $variant->id,
                itemReference: (string) $variant->item_reference,
                unitCost: (float) $variant->cost_price,
                organizationId: (int) $organization->id,
                locationId: (int) $tienda,
                zones: $zones,
                codec: $codec,
                companyPrefix: $prefix,
            );
        }

        $this->command?->info("Semilla completada: {$created} tags generados.");
    }

    /** @param array<string, int> $zones */
    private function seedVariant(
        int $variantId,
        string $itemReference,
        float $unitCost,
        int $organizationId,
        int $locationId,
        array $zones,
        EpcCodec $codec,
        string $companyPrefix,
    ): int {
        $count = mt_rand(self::MIN_PER_VARIANT, self::MAX_PER_VARIANT);

        DB::table('product_variant_counters')->insertOrIgnore([
            'product_variant_id' => $variantId,
            'last_serial' => 0,
        ]);

        // Una sola reserva para toda la variante: pedir serial a serial son
        // `count` idas y vueltas a la base por variante, y con doce variantes
        // eso ya se nota al sembrar.
        $range = DB::selectOne('SELECT * FROM reserve_serial_range(?, ?)', [$variantId, $count]);
        $serial = (int) $range->serial_from;

        $created = 0;

        for ($i = 0; $i < $count; $i++, $serial++) {
            $epc = $codec->encode($companyPrefix, $itemReference, $serial);

            $commissionedAt = now()->subDays(mt_rand(30, 200))->subMinutes(mt_rand(0, 1439));

            $tag = Tag::create([
                'organization_id' => $organizationId,
                'epc' => $epc,
                'epc_scheme' => 'sgtin-96',
                'tid' => 'E280'.strtoupper(substr(md5($epc), 0, 20)),
                'product_variant_id' => $variantId,
                'state' => TagState::Codificado,
                'commissioned_at' => $commissionedAt,
                'first_seen_at' => $commissionedAt,
                'decoded_company_prefix' => $companyPrefix,
                'decoded_item_reference' => $itemReference,
                'decoded_serial' => $serial,
            ]);

            $zone = $this->pickZone($zones);

            $this->movements->apply(new MovementIntent(
                tagId: $tag->id,
                type: MovementType::Tarado,
                toLocationId: $locationId,
                toZoneId: $zone,
                referenceType: 'seed',
                reason: 'Carga inicial de datos de desarrollo',
                unitCost: $unitCost,
                occurredAt: $commissionedAt,
            ));

            $this->applyOutcome($tag->id, $locationId, $zone, $unitCost, $commissionedAt);

            $created++;
        }

        return $created;
    }

    /**
     * Reparto realista de estados. La mayoría en stock; algunas vendidas; unas
     * pocas sin ver o perdidas, que son las que dan trabajo a las pantallas de
     * conciliación y merma.
     */
    private function applyOutcome(
        int $tagId,
        int $locationId,
        ?int $zone,
        float $unitCost,
        Carbon $commissionedAt,
    ): void {
        $roll = mt_rand(1, 100);

        /*
         * El movimiento posterior cae siempre entre una hora después del
         * tarado y ahora mismo. Las dos cotas importan y las dos costaron un
         * fallo:
         *
         *  - Antes del tarado, el último movimiento por fecha pasaría a ser
         *    el propio tarado y la reconstrucción diría «en stock».
         *  - Después de ahora, `stock_as_of(loc, now())` filtra por
         *    `occurred_at <= now()` y no lo vería, con el mismo resultado.
         *    Sin esta cota salían 10 movimientos con fecha futura y el
         *    control de integridad devolvía 8 filas.
         */
        $after = function (int $maxDays) use ($commissionedAt): Carbon {
            $earliest = $commissionedAt->copy()->addHour();
            $latest = min(
                $commissionedAt->copy()->addDays($maxDays)->getTimestamp(),
                now()->getTimestamp(),
            );

            if ($latest <= $earliest->getTimestamp()) {
                return $earliest->min(now());
            }

            return Carbon::createFromTimestamp(
                mt_rand($earliest->getTimestamp(), $latest),
            );
        };

        if ($roll <= 72) {
            return; // se queda en stock
        }

        if ($roll <= 94) {
            $this->movements->apply(new MovementIntent(
                tagId: $tagId,
                type: MovementType::Venta,
                toLocationId: $locationId,
                referenceType: 'seed',
                reason: 'Venta simulada',
                unitCost: $unitCost,
                occurredAt: $after(60),
            ));

            return;
        }

        if ($roll <= 98) {
            // `ajuste_negativo` deja el tag en `no_visto`: es lo que hace la
            // conciliación cuando un ciclo no lo encuentra.
            $this->movements->apply(new MovementIntent(
                tagId: $tagId,
                type: MovementType::AjusteNegativo,
                toLocationId: $locationId,
                toZoneId: $zone,
                referenceType: 'seed',
                reason: 'No detectada en el último ciclo',
                unitCost: $unitCost,
                occurredAt: $after(20),
            ));

            return;
        }

        // Perdida: primero deja de verse y después se declara merma. El
        // sistema no permite saltar de `en_stock` a `perdido` de golpe, y esa
        // restricción existe para que nadie declare merma sin dejar rastro de
        // los ciclos en los que la prenda ya faltaba.
        $missedAt = $after(20);

        $this->movements->apply(new MovementIntent(
            tagId: $tagId,
            type: MovementType::AjusteNegativo,
            toLocationId: $locationId,
            toZoneId: $zone,
            referenceType: 'seed',
            reason: 'No detectada en el último ciclo',
            unitCost: $unitCost,
            occurredAt: $missedAt,
        ));

        $this->movements->apply(new MovementIntent(
            tagId: $tagId,
            type: MovementType::Merma,
            referenceType: 'seed',
            reason: 'Merma declarada tras dos ciclos sin verla',
            unitCost: $unitCost,
            // Igual que arriba: nunca más allá de ahora.
            occurredAt: $missedAt->copy()->addDays(mt_rand(1, 15))->min(now()),
        ));
    }

    /** @param array<string, int> $zones */
    private function pickZone(array $zones): ?int
    {
        $roll = mt_rand(1, 100);

        $code = match (true) {
            $roll <= 55 => 'SALA',
            $roll <= 75 => 'TRA-A',
            $roll <= 92 => 'TRA-B',
            $roll <= 97 => 'ESC',
            default => 'PROB',
        };

        return $zones[$code] ?? null;
    }
}
