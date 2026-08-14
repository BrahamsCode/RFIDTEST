<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AlertKind;
use App\Services\AlertService;
use App\Services\AuditReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Informes de auditoría de `docs/12` §6.
 *
 * El de accesos fuera de horario levanta alerta; los demás solo informan.
 * Es a propósito: convertir en alerta un informe mensual que casi siempre
 * tiene filas haría que se acabara silenciando, y con él los que sí importan.
 */
final class AuditReport extends Command
{
    protected $signature = 'traza:audit-report
                            {tipo=todos : ajustes|mermas|dispositivos|accesos|todos}
                            {--days=30 : Ventana en días}
                            {--json : Salida en JSON, para archivar el informe}';

    protected $description = 'Informes periódicos de auditoría (docs/12 §6)';

    public function handle(AuditReportService $reports, AlertService $alerts): int
    {
        $days = (int) $this->option('days');
        $from = now()->subDays($days);
        $tipo = (string) $this->argument('tipo');

        $salida = [];

        if (in_array($tipo, ['ajustes', 'todos'], true)) {
            $salida['ajustes_manuales'] = $reports->manualAdjustments($from);
        }

        if (in_array($tipo, ['mermas', 'todos'], true)) {
            $salida['mermas'] = $reports->shrinkage($from);
        }

        if (in_array($tipo, ['dispositivos', 'todos'], true)) {
            $salida['cambios_dispositivos'] = $reports->deviceChanges($from);
        }

        if (in_array($tipo, ['accesos', 'todos'], true)) {
            $salida['accesos_fuera_horario'] = $reports->afterHoursAccess($from);
            $this->alertOnAfterHours($alerts, $salida['accesos_fuera_horario']);
        }

        if ($salida === []) {
            $this->error("Tipo «{$tipo}» no reconocido.");

            return self::INVALID;
        }

        if ($this->option('json')) {
            $this->line(json_encode($salida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->render($salida);

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $salida */
    private function render(array $salida): void
    {
        foreach ($salida as $nombre => $informe) {
            $this->newLine();
            $this->info(str_replace('_', ' ', ucfirst($nombre))
                ." · {$informe['desde']} a {$informe['hasta']}");

            if ($informe['filas'] === []) {
                $this->line('  Sin movimientos en el periodo.');

                continue;
            }

            $columnas = array_keys($informe['filas'][0]);

            $this->table($columnas, array_map(
                fn (array $f) => array_map(
                    fn ($v) => is_bool($v) ? ($v ? '⚠ SÍ' : '') : (is_array($v) ? json_encode($v) : (string) $v),
                    $f,
                ),
                array_slice($informe['filas'], 0, 25),
            ));

            $atipicos = array_filter($informe['filas'], fn (array $f) => ($f['atipico'] ?? false) === true);

            if ($atipicos !== []) {
                // El informe más útil contra el fraude interno: quien
                // concentra ajustes negativos, aunque cada uno por separado
                // parezca razonable.
                $this->warn(sprintf(
                    '  %d persona(s) triplican la mediana del equipo. Merecen una conversación.',
                    count($atipicos),
                ));
            }
        }
    }

    /** @param array{filas: list<array<string, mixed>>} $informe */
    private function alertOnAfterHours(AlertService $alerts, array $informe): void
    {
        if ($informe['filas'] === []) {
            return;
        }

        $organizationId = DB::table('organizations')->orderBy('id')->value('id');

        if ($organizationId === null) {
            return;
        }

        $porUsuario = [];
        foreach ($informe['filas'] as $fila) {
            $porUsuario[$fila['usuario'] ?? 'desconocido'] = ($porUsuario[$fila['usuario'] ?? 'desconocido'] ?? 0) + 1;
        }

        $alerts->raise(
            AlertKind::EpcDesconocido,
            detail: [
                'origen' => 'traza:audit-report',
                'informe' => 'accesos_fuera_horario',
                'total' => count($informe['filas']),
                'por_usuario' => $porUsuario,
                'nota' => 'Un acceso nocturno no prueba nada por sí solo, pero es lo primero '
                    .'que se mira cuando aparece un descuadre.',
            ],
            organizationId: (int) $organizationId,
            severity: 4,
        );

        $this->warn(sprintf(
            '  %d acceso(s) fuera de horario. Alerta levantada.',
            count($informe['filas']),
        ));
    }
}
