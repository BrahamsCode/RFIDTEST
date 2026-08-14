<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Console\Commands\ListenPortalEvents;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Métricas del lado servidor en formato Prometheus. Ver `docs/11` §7.
 *
 * Las cuatro series que nombran las alertas del documento —el suscriptor de
 * portal, las filas en `tag_reads_default`, la discrepancia de proyección y
 * la espera de la cola— salen de aquí; el resto de `docs/07` §9 las sirve el
 * borde en su propio `/metrics`.
 *
 * Las consultas caras van con caché corta. Prometheus sondea cada 30 s y la
 * comprobación de discrepancia recorre `stock_movements` entero: sin caché,
 * la propia observabilidad sería la consulta más pesada del sistema.
 */
final class MetricsController extends Controller
{
    /** Suficiente para no repetir la consulta entre sondeos consecutivos. */
    private const CACHE_SECONDS = 55;

    public function __invoke(): Response
    {
        $lines = [
            ...$this->portalListener(),
            ...$this->defaultPartition(),
            ...$this->projectionMismatch(),
            ...$this->queueDepth(),
            ...$this->openAlerts(),
        ];

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; version=0.0.4',
        ]);
    }

    /**
     * ¿Vive el suscriptor de portal?
     *
     * Se deduce del mismo fichero que toca el propio proceso para el
     * healthcheck del contenedor. Es el camino crítico de la alarma
     * antihurto: si muere, nadie se entera hasta que roban algo.
     *
     * @return list<string>
     */
    private function portalListener(): array
    {
        $file = ListenPortalEvents::LIVENESS_FILE;
        $mtime = is_file($file) ? (int) filemtime($file) : 0;

        // Dos minutos de margen sobre los 30 s a los que lo refresca el bucle.
        $alive = $mtime > 0 && (time() - $mtime) < 120;

        return [
            '# HELP traza_portal_listener_alive 1 si el suscriptor de portal refrescó su señal de vida.',
            '# TYPE traza_portal_listener_alive gauge',
            'traza_portal_listener_alive '.($alive ? 1 : 0),
        ];
    }

    /** @return list<string> */
    private function defaultPartition(): array
    {
        $rows = Cache::remember('metrics:tag_reads_default', self::CACHE_SECONDS, function (): int {
            if (DB::selectOne("SELECT to_regclass('public.tag_reads_default') AS oid")->oid === null) {
                return 0;
            }

            return (int) DB::table('tag_reads_default')->count();
        });

        return [
            '# HELP traza_tag_reads_default_rows Lecturas que cayeron fuera de toda partición mensual.',
            '# TYPE traza_tag_reads_default_rows gauge',
            "traza_tag_reads_default_rows {$rows}",
        ];
    }

    /**
     * Unidades de diferencia entre la proyección y la reconstrucción.
     *
     * Cualquier valor distinto de cero es crítico: significa que hay un
     * camino que muta `tags` sin pasar por `StockMovementService`.
     *
     * @return list<string>
     */
    private function projectionMismatch(): array
    {
        $total = Cache::remember('metrics:projection_mismatch', self::CACHE_SECONDS, function (): int {
            $row = DB::selectOne(<<<'SQL'
                SELECT COALESCE(SUM(ABS(diff)), 0) AS total FROM (
                    SELECT COALESCE(p.qty, 0) - COALESCE(r.quantity, 0) AS diff
                    FROM (
                        SELECT current_location_id AS loc, product_variant_id, COUNT(*) AS qty
                        FROM tags WHERE state = 'en_stock' AND current_location_id IS NOT NULL
                        GROUP BY 1, 2
                    ) p
                    FULL OUTER JOIN (
                        SELECT l.id AS loc, s.product_variant_id, s.quantity
                        FROM locations l, LATERAL stock_as_of(l.id, now()) s
                    ) r ON r.loc = p.loc AND r.product_variant_id = p.product_variant_id
                    WHERE COALESCE(p.qty, 0) <> COALESCE(r.quantity, 0)
                ) d
            SQL);

            return (int) ($row->total ?? 0);
        });

        return [
            '# HELP traza_stock_projection_mismatch Unidades de diferencia entre proyección y movimientos.',
            '# TYPE traza_stock_projection_mismatch gauge',
            "traza_stock_projection_mismatch {$total}",
        ];
    }

    /**
     * Profundidad de la cola de lecturas.
     *
     * Es lo que se puede medir sin Horizon delante; el documento habla de
     * `horizon_queue_wait_seconds`, que solo existe con el exporter de
     * Horizon. La profundidad sirve para lo mismo: si crece y no baja, el
     * consumidor no da abasto.
     *
     * @return list<string>
     */
    private function queueDepth(): array
    {
        $lines = [
            '# HELP traza_queue_depth Trabajos pendientes por cola.',
            '# TYPE traza_queue_depth gauge',
        ];

        foreach (['reads', 'default'] as $queue) {
            $depth = 0;

            try {
                $depth = (int) app('queue')->connection()->size($queue);
            } catch (\Throwable) {
                // Sin Redis levantado la métrica no está; mejor un cero
                // explícito que tirar el endpoint entero y perder las demás.
            }

            $lines[] = sprintf('traza_queue_depth{queue="%s"} %d', $queue, $depth);
        }

        return $lines;
    }

    /** @return list<string> */
    private function openAlerts(): array
    {
        $counts = Cache::remember('metrics:open_alerts', self::CACHE_SECONDS, fn (): array => DB::table('alerts')
            ->whereIn('status', ['abierta', 'en_revision'])
            ->selectRaw('kind::text AS kind, count(*) AS total')
            ->groupBy('kind')
            ->pluck('total', 'kind')
            ->all());

        $lines = [
            '# HELP traza_open_alerts Alertas abiertas o en revisión, por tipo.',
            '# TYPE traza_open_alerts gauge',
        ];

        foreach ($counts as $kind => $total) {
            $lines[] = sprintf('traza_open_alerts{kind="%s"} %d', $kind, $total);
        }

        if ($counts === []) {
            $lines[] = 'traza_open_alerts{kind="none"} 0';
        }

        return $lines;
    }
}
