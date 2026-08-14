<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Exportación a frío de las lecturas crudas. Ver `docs/13` §6.
 *
 * El orden es innegociable: **exportar, verificar, y solo entonces purgar**.
 * Una partición borrada sin exportar son tres meses de trazabilidad que no
 * vuelven, y en una investigación de merma es justo lo que hace falta mirar.
 *
 * `traza:rotate-partitions --purge` consulta la auditoría que deja este
 * comando y se niega a borrar lo que no conste exportado y verificado.
 */
final class ExportColdReads extends Command
{
    public const AUDIT_ACTION = 'cold_export.completed';

    protected $signature = 'traza:export-cold-reads
                            {--month= : Mes a exportar, formato YYYY_MM. Por defecto, hace 4 meses}
                            {--disk=s3 : Disco de destino}';

    protected $description = 'Exporta una partición de tag_reads a almacenamiento frío y la verifica';

    public function handle(): int
    {
        $month = (string) ($this->option('month') ?? now()->subMonths(4)->format('Y_m'));

        if (preg_match('/^\d{4}_\d{2}$/', $month) !== 1) {
            $this->error("El mes «{$month}» no tiene el formato YYYY_MM.");

            return self::INVALID;
        }

        $table = "tag_reads_{$month}";

        if (! $this->tableExists($table)) {
            $this->info("No existe la partición {$table}; nada que exportar.");

            return self::SUCCESS;
        }

        $rows = (int) DB::table($table)->count();
        $localPath = $this->export($table);

        if ($localPath === null) {
            return self::FAILURE;
        }

        $remote = "cold-reads/{$table}.csv.gz";
        $disk = (string) $this->option('disk');

        try {
            Storage::disk($disk)->put($remote, file_get_contents($localPath));
        } catch (\Throwable $e) {
            $this->error("No se pudo subir {$remote}: {$e->getMessage()}");

            return self::FAILURE;
        }

        /*
         * Verificación por tamaño y no solo por «la subida no lanzó
         * excepción»: un fallo a mitad de transferencia deja el objeto
         * truncado y el proveedor lo da por bueno. Comparar bytes es barato y
         * es lo único que separa una copia de una ilusión.
         */
        $localSize = filesize($localPath);
        $remoteSize = Storage::disk($disk)->size($remote);

        if ($remoteSize !== $localSize) {
            $this->error(
                "La copia de {$remote} mide {$remoteSize} bytes y el origen {$localSize}. "
                .'NO se marca como verificada: la partición no debe purgarse.'
            );

            return self::FAILURE;
        }

        $this->audit($table, $rows, $localSize, $remote, $disk);

        $this->info(sprintf(
            'Exportado %s: %s filas, %s bytes en %s. Ya puede purgarse la partición.',
            $table,
            number_format($rows, 0, ',', ' '),
            number_format($localSize, 0, ',', ' '),
            $remote,
        ));

        return self::SUCCESS;
    }

    /** @return string|null ruta local del fichero, o null si falló */
    private function export(string $table): ?string
    {
        $directory = storage_path('app/cold');

        if (! is_dir($directory) && ! mkdir($directory, 0o775, true) && ! is_dir($directory)) {
            $this->error("No se pudo crear {$directory}.");

            return null;
        }

        $path = "{$directory}/{$table}.csv.gz";

        try {
            /*
             * No se usa `COPY`. `COPY ... TO PROGRAM` lo ejecuta el servidor
             * de PostgreSQL: exige superusuario y que la ruta exista **en el
             * servidor**, cosa que no se cumple con la base en otro
             * contenedor. Y `COPY ... TO STDOUT` no pasa por una sentencia
             * preparada de PDO —falla con «General error: 3»—, así que
             * tampoco sirve desde aquí.
             *
             * Se recorre con cursor por lotes y se escribe con `fputcsv`. Es
             * más lento que `COPY`, pero la memoria queda acotada aunque la
             * partición tenga millones de filas, y funciona en cualquier
             * despliegue sin privilegios especiales. Es un trabajo mensual:
             * la velocidad no es lo que decide aquí.
             */
            $handle = gzopen($path, 'wb9');

            if ($handle === false) {
                $this->error("No se pudo abrir {$path} para escritura.");

                return null;
            }

            $header = false;
            $bar = $this->output->createProgressBar();

            DB::table($table)->orderBy('id')->lazyById(5000, 'id')->each(
                function (object $row) use ($handle, &$header, $bar): void {
                    $fields = (array) $row;

                    if (! $header) {
                        gzwrite($handle, $this->csvLine(array_keys($fields)));
                        $header = true;
                    }

                    gzwrite($handle, $this->csvLine(array_values($fields)));
                    $bar->advance();
                },
            );

            // Una partición vacía tiene que producir un fichero con cabecera
            // igualmente: un CSV de cero bytes no se distingue de un fallo.
            if (! $header) {
                gzwrite($handle, $this->csvLine($this->columnsOf($table)));
            }

            gzclose($handle);
            $bar->finish();
            $this->newLine();
        } catch (\Throwable $e) {
            $this->error("Fallo al exportar {$table}: {$e->getMessage()}");

            return null;
        }

        return $path;
    }

    /** @param list<mixed> $fields */
    private function csvLine(array $fields): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_map(
            fn ($v) => $v === null ? '' : (is_bool($v) ? ($v ? 't' : 'f') : (string) $v),
            $fields,
        ), ',', '"', '\\');
        rewind($out);
        $line = (string) stream_get_contents($out);
        fclose($out);

        return $line;
    }

    /** @return list<string> */
    private function columnsOf(string $table): array
    {
        return array_map(
            fn (object $r) => (string) $r->column_name,
            DB::select(
                'SELECT column_name FROM information_schema.columns
                 WHERE table_name = ? ORDER BY ordinal_position',
                [$table],
            ),
        );
    }

    private function audit(string $table, int $rows, int $bytes, string $remote, string $disk): void
    {
        DB::table('audit_logs')->insert([
            'action' => self::AUDIT_ACTION,
            'subject_type' => 'partition',
            'changes' => json_encode([
                'partition' => $table,
                'rows' => $rows,
                'bytes' => $bytes,
                'object' => $remote,
                'disk' => $disk,
                // La bandera que mira `traza:rotate-partitions --purge`.
                'verified' => true,
            ]),
            'created_at' => now(),
        ]);
    }

    private function tableExists(string $table): bool
    {
        return DB::selectOne('SELECT to_regclass(?) AS oid', ['public.'.$table])->oid !== null;
    }
}
