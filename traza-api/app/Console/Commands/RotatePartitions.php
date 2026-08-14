<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AlertKind;
use App\Services\AlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rotación mensual de las particiones de `tag_reads`. Ver `docs/05` §5.
 *
 * Se programa el día 20 y no el 1 a propósito: crea las particiones de los
 * **dos** meses siguientes, así que si el trabajo falla queda un mes entero
 * de margen para enterarse. Con la partición del mes en curso creada el
 * mismo día 1, un fallo silencioso significa que las lecturas caen en
 * `tag_reads_default` desde el primer minuto.
 */
final class RotatePartitions extends Command
{
    protected $signature = 'traza:rotate-partitions
                            {--keep=3 : Meses de particiones a conservar}
                            {--purge : Elimina las particiones antiguas; sin esto solo se informa}';

    protected $description = 'Crea las particiones de tag_reads de los próximos meses y purga las antiguas';

    public function handle(AlertService $alerts): int
    {
        $created = $this->ensureUpcoming();
        $orphans = $this->checkDefaultPartition($alerts);
        $purged = $this->purgeOld();

        $this->table(
            ['Resultado', 'Detalle'],
            [
                ['Particiones creadas', $created === [] ? 'ninguna (ya existían)' : implode(', ', $created)],
                ['Filas en tag_reads_default', (string) $orphans],
                ['Particiones purgadas', $purged === [] ? 'ninguna' : implode(', ', $purged)],
            ],
        );

        return $orphans > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Particiones del mes que viene y del siguiente.
     *
     * @return list<string>
     */
    private function ensureUpcoming(): array
    {
        $created = [];

        foreach ([1, 2] as $monthsAhead) {
            $date = now()->addMonths($monthsAhead)->startOfMonth();
            $name = 'tag_reads_'.$date->format('Y_m');

            if ($this->partitionExists($name)) {
                continue;
            }

            DB::select('SELECT ensure_tag_reads_partition(?::date)', [$date->toDateString()]);
            $created[] = $name;
        }

        return $created;
    }

    /**
     * Filas en la partición por defecto.
     *
     * Una sola fila ahí significa que en algún momento no existía la
     * partición del mes: las lecturas no se perdieron, pero están fuera del
     * esquema de retención y la purga no las va a tocar nunca. Es un fallo
     * silencioso y por eso levanta alerta.
     */
    private function checkDefaultPartition(AlertService $alerts): int
    {
        if (! $this->partitionExists('tag_reads_default')) {
            return 0;
        }

        $count = (int) DB::table('tag_reads_default')->count();

        if ($count === 0) {
            return 0;
        }

        $this->error("tag_reads_default tiene {$count} filas: hubo lecturas sin partición de destino.");

        // `alerts.organization_id` es NOT NULL y esto es un problema de
        // infraestructura, sin organización natural: se cuelga de la primera,
        // que en una instalación de una empresa es la única.
        $organizationId = DB::table('organizations')->orderBy('id')->value('id');

        if ($organizationId !== null) {
            $alerts->raise(
                AlertKind::StockNegativo,
                detail: [
                    'origen' => 'traza:rotate-partitions',
                    'tabla' => 'tag_reads_default',
                    'filas' => $count,
                    'accion' => 'Crear la partición del mes afectado y mover las filas antes de purgar.',
                ],
                organizationId: (int) $organizationId,
                severity: 1,
            );
        }

        return $count;
    }

    /** @return list<string> */
    private function purgeOld(): array
    {
        $keep = (int) $this->option('keep');

        if (! $this->option('purge')) {
            $candidates = $this->oldPartitions($keep);

            if ($candidates !== []) {
                $this->warn(
                    'Se purgarían: '.implode(', ', $candidates)
                    .'. Ejecute con --purge, y solo si la exportación a frío de esos meses está verificada.'
                );
            }

            return [];
        }

        /*
         * El orden de `docs/13` §6 es innegociable: exportar primero, purgar
         * después, y solo si la exportación se verificó. Aquí se comprueba
         * antes de borrar nada — una partición purgada sin exportar son tres
         * meses de trazabilidad que no vuelven.
         */
        $unexported = $this->unexportedAmong($this->oldPartitions($keep));

        if ($unexported !== []) {
            $this->error(
                'Estas particiones no tienen exportación verificada y NO se purgan: '
                .implode(', ', $unexported).'. Ejecute antes traza:export-cold-reads.'
            );

            return [];
        }

        return array_map(
            fn (object $row) => (string) $row->drop_old_tag_reads_partitions,
            DB::select('SELECT * FROM drop_old_tag_reads_partitions(?)', [$keep]),
        );
    }

    /** @return list<string> */
    private function oldPartitions(int $keepMonths): array
    {
        $cutoff = now()->startOfMonth()->subMonths($keepMonths);

        return array_values(array_filter(
            $this->partitionNames(),
            function (string $name) use ($cutoff): bool {
                if (preg_match('/^tag_reads_(\d{4})_(\d{2})$/', $name, $m) !== 1) {
                    return false;
                }

                return sprintf('%s-%s', $m[1], $m[2]) < $cutoff->format('Y-m');
            },
        ));
    }

    /**
     * @param  list<string>  $partitions
     * @return list<string>
     */
    private function unexportedAmong(array $partitions): array
    {
        if ($partitions === []) {
            return [];
        }

        $exported = DB::table('audit_logs')
            ->where('action', ExportColdReads::AUDIT_ACTION)
            ->whereRaw("changes->>'verified' = 'true'")
            ->pluck('changes')
            ->map(fn ($json) => json_decode((string) $json, true)['partition'] ?? null)
            ->filter()
            ->all();

        return array_values(array_diff($partitions, $exported));
    }

    /** @return list<string> */
    private function partitionNames(): array
    {
        return array_map(
            fn (object $row) => (string) $row->relname,
            DB::select(<<<'SQL'
                SELECT c.relname
                FROM pg_class c
                JOIN pg_inherits i ON i.inhrelid = c.oid
                JOIN pg_class p ON p.oid = i.inhparent
                WHERE p.relname = 'tag_reads'
            SQL),
        );
    }

    private function partitionExists(string $name): bool
    {
        return DB::selectOne('SELECT to_regclass(?) AS oid', ['public.'.$name])->oid !== null;
    }
}
