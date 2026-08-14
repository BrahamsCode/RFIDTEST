<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AlertKind;
use App\Models\Location;
use App\Services\AlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Control de integridad nocturno. Ver `docs/05` §6.
 *
 * Compara la proyección `tags` con la reconstrucción desde
 * `stock_movements`. Si difieren, la cifra que ve el dueño de la tienda no es
 * real y no hay forma de saber cuál de las dos miente sin mirar.
 *
 * **Este control no debe silenciarse nunca.** No hay opción para excluir
 * ubicaciones ni para bajar el umbral: cualquier diferencia, aunque sea de
 * una unidad, significa que hay un camino de código que muta `tags` sin pasar
 * por `StockMovementService`, y eso solo empeora.
 */
final class CheckProjection extends Command
{
    protected $signature = 'traza:check-projection
                            {--location= : Comprueba solo una ubicación}
                            {--quiet-when-clean : No imprime nada si todo cuadra; para cron}';

    protected $description = 'Compara la proyección de stock con la reconstrucción desde movimientos';

    public function handle(AlertService $alerts): int
    {
        $locations = Location::query()
            ->when($this->option('location'), fn ($q) => $q->whereKey((int) $this->option('location')))
            ->orderBy('id')
            ->get();

        $problems = [];

        foreach ($locations as $location) {
            foreach ($this->discrepancies($location->id) as $row) {
                $problems[] = [
                    'location_id' => $location->id,
                    'location' => $location->code,
                    'product_variant_id' => (int) $row->product_variant_id,
                    'proyectado' => (int) $row->proyectado,
                    'reconstruido' => (int) $row->reconstruido,
                    'diferencia' => (int) $row->proyectado - (int) $row->reconstruido,
                ];
            }
        }

        if ($problems === []) {
            if (! $this->option('quiet-when-clean')) {
                $this->info("Proyección y movimientos cuadran en {$locations->count()} ubicación(es).");
            }

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'La proyección no cuadra con los movimientos: %d variante(s) en %d ubicación(es).',
            count($problems),
            count(array_unique(array_column($problems, 'location_id'))),
        ));

        $this->table(
            ['Tienda', 'Variante', 'Proyectado', 'Reconstruido', 'Diferencia'],
            array_map(fn (array $p) => [
                $p['location'], $p['product_variant_id'],
                $p['proyectado'], $p['reconstruido'], $p['diferencia'],
            ], array_slice($problems, 0, 20)),
        );

        $this->raiseAlert($alerts, $problems);

        return self::FAILURE;
    }

    /** @return list<object> */
    private function discrepancies(int $locationId): array
    {
        // Consulta literal de `docs/05` §6.
        return DB::select(<<<'SQL'
            WITH proyectado AS (
                SELECT product_variant_id, COUNT(*) AS qty
                FROM tags
                WHERE state = 'en_stock' AND current_location_id = ?
                GROUP BY 1
            ),
            reconstruido AS (
                SELECT * FROM stock_as_of(?, now())
            )
            SELECT
                COALESCE(p.product_variant_id, r.product_variant_id) AS product_variant_id,
                COALESCE(p.qty, 0)      AS proyectado,
                COALESCE(r.quantity, 0) AS reconstruido
            FROM proyectado p
            FULL OUTER JOIN reconstruido r USING (product_variant_id)
            WHERE COALESCE(p.qty, 0) <> COALESCE(r.quantity, 0)
            ORDER BY 1
        SQL, [$locationId, $locationId]);
    }

    /** @param list<array<string, mixed>> $problems */
    private function raiseAlert(AlertService $alerts, array $problems): void
    {
        $byLocation = [];

        foreach ($problems as $p) {
            $byLocation[$p['location_id']][] = $p;
        }

        foreach ($byLocation as $locationId => $rows) {
            $location = Location::find($locationId);

            $alerts->raise(
                AlertKind::StockNegativo,
                detail: [
                    'origen' => 'traza:check-projection',
                    'tienda' => $location?->code,
                    'variantes_afectadas' => count($rows),
                    'unidades_de_diferencia' => array_sum(array_map(
                        fn (array $r) => abs($r['diferencia']),
                        $rows,
                    )),
                    // Se guardan las 20 primeras: el detalle completo se
                    // saca volviendo a ejecutar el comando, y una alerta con
                    // 4 000 filas dentro no la lee nadie.
                    'muestra' => array_slice($rows, 0, 20),
                ],
                organizationId: $location?->organization_id,
                severity: 1,
            );
        }
    }
}
