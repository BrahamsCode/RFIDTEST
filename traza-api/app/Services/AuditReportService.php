<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Informes de auditoría de `docs/12` §6.
 *
 * El más útil contra el fraude interno es el de **ajustes manuales por
 * usuario**: un empleado que concentra un número anormal de ajustes negativos
 * merece una conversación, aunque cada ajuste por separado parezca razonable.
 * Por eso los informes no devuelven solo el total, sino la desviación
 * respecto al resto del equipo: un número suelto no dice si es mucho.
 */
final class AuditReportService
{
    /**
     * Cuántas veces la mediana tiene que superar alguien para que se le
     * señale. Con 2× una tienda con dos personas siempre marcaría a una.
     */
    private const FACTOR_ATIPICO = 3.0;

    /**
     * Ajustes manuales por usuario. Mensual, lo revisa el supervisor regional.
     *
     * @return array{desde: string, hasta: string, filas: list<array<string, mixed>>}
     */
    public function manualAdjustments(Carbon $from, ?Carbon $to = null): array
    {
        $to ??= now();

        $filas = DB::select(<<<'SQL'
            SELECT m.user_id,
                   u.name                                            AS usuario,
                   l.code                                            AS tienda,
                   count(*)                                          AS total,
                   count(*) FILTER (WHERE m.movement_type = 'ajuste_negativo') AS negativos,
                   count(*) FILTER (WHERE m.movement_type = 'ajuste_positivo') AS positivos,
                   count(*) FILTER (WHERE m.movement_type = 'merma')           AS mermas,
                   COALESCE(sum(m.unit_cost) FILTER (
                       WHERE m.movement_type IN ('ajuste_negativo', 'merma')
                   ), 0)                                             AS valor_perdido
            FROM stock_movements m
            LEFT JOIN users u     ON u.id = m.user_id
            LEFT JOIN locations l ON l.id = COALESCE(m.from_location_id, m.to_location_id)
            WHERE m.movement_type IN ('ajuste_negativo', 'ajuste_positivo', 'merma')
              AND m.occurred_at BETWEEN ? AND ?
            GROUP BY 1, 2, 3
            ORDER BY negativos DESC, total DESC
        SQL, [$from, $to]);

        return [
            'desde' => $from->toDateString(),
            'hasta' => $to->toDateString(),
            'filas' => $this->markOutliers($filas, 'negativos'),
        ];
    }

    /**
     * Mermas declaradas por usuario y tienda. Mensual, lo revisa gerencia.
     *
     * @return array{desde: string, hasta: string, filas: list<array<string, mixed>>}
     */
    public function shrinkage(Carbon $from, ?Carbon $to = null): array
    {
        $to ??= now();

        $filas = DB::select(<<<'SQL'
            SELECT l.code                          AS tienda,
                   u.name                          AS usuario,
                   count(*)                        AS unidades,
                   COALESCE(sum(m.unit_cost), 0)   AS valor,
                   min(m.occurred_at)              AS primera,
                   max(m.occurred_at)              AS ultima
            FROM stock_movements m
            LEFT JOIN users u     ON u.id = m.user_id
            LEFT JOIN locations l ON l.id = COALESCE(m.from_location_id, m.to_location_id)
            WHERE m.movement_type = 'merma'
              AND m.occurred_at BETWEEN ? AND ?
            GROUP BY 1, 2
            ORDER BY valor DESC
        SQL, [$from, $to]);

        return [
            'desde' => $from->toDateString(),
            'hasta' => $to->toDateString(),
            'filas' => $this->markOutliers($filas, 'unidades'),
        ];
    }

    /**
     * Cambios de configuración de dispositivos. Mensual, responsable técnico.
     *
     * Importa porque cambiar la potencia de un lector puede provocar que deje
     * de leer una zona, y esa desaparición se parece mucho a una merma.
     *
     * @return array{desde: string, hasta: string, filas: list<array<string, mixed>>}
     */
    public function deviceChanges(Carbon $from, ?Carbon $to = null): array
    {
        $to ??= now();

        $filas = DB::select(<<<'SQL'
            SELECT a.created_at   AS cuando,
                   u.name         AS usuario,
                   a.action       AS accion,
                   a.subject_id   AS dispositivo_id,
                   d.code         AS dispositivo,
                   a.changes      AS cambios,
                   host(a.ip_address) AS ip
            FROM audit_logs a
            LEFT JOIN users u   ON u.id = a.user_id
            LEFT JOIN devices d ON d.id = a.subject_id
            WHERE a.subject_type LIKE '%Device'
              AND a.created_at BETWEEN ? AND ?
            ORDER BY a.created_at DESC
        SQL, [$from, $to]);

        return [
            'desde' => $from->toDateString(),
            'hasta' => $to->toDateString(),
            'filas' => array_map(fn (object $r) => (array) $r, $filas),
        ];
    }

    /**
     * Accesos fuera de horario comercial. Semanal, genera alerta.
     *
     * La franja es 07:00–22:00 hora de Lima. Una sesión a las tres de la
     * mañana no prueba nada por sí sola —hay quien cuadra inventario de
     * noche—, pero es lo primero que se mira cuando aparece un descuadre.
     *
     * @return array{desde: string, hasta: string, filas: list<array<string, mixed>>}
     */
    public function afterHoursAccess(Carbon $from, ?Carbon $to = null): array
    {
        $to ??= now();

        $filas = DB::select(<<<'SQL'
            SELECT u.name              AS usuario,
                   a.action            AS accion,
                   a.created_at        AS cuando,
                   host(a.ip_address)  AS ip,
                   EXTRACT(HOUR FROM a.created_at AT TIME ZONE 'America/Lima')::int AS hora_local
            FROM audit_logs a
            LEFT JOIN users u ON u.id = a.user_id
            WHERE a.created_at BETWEEN ? AND ?
              AND a.user_id IS NOT NULL
              AND (
                  EXTRACT(HOUR FROM a.created_at AT TIME ZONE 'America/Lima') < 7
                  OR EXTRACT(HOUR FROM a.created_at AT TIME ZONE 'America/Lima') >= 22
              )
            ORDER BY a.created_at DESC
        SQL, [$from, $to]);

        return [
            'desde' => $from->toDateString(),
            'hasta' => $to->toDateString(),
            'filas' => array_map(fn (object $r) => (array) $r, $filas),
        ];
    }

    /**
     * Marca como atípico a quien triplica la mediana del grupo.
     *
     * La mediana y no la media: con cinco personas, una sola con cien ajustes
     * arrastra la media hasta el punto de que ella misma parece normal.
     *
     * @param  list<object>  $filas
     * @return list<array<string, mixed>>
     */
    private function markOutliers(array $filas, string $campo): array
    {
        $valores = array_map(fn (object $r) => (float) $r->{$campo}, $filas);
        sort($valores);

        $mediana = $valores === []
            ? 0.0
            : $valores[intdiv(count($valores), 2)];

        return array_map(function (object $r) use ($campo, $mediana): array {
            $fila = (array) $r;
            $fila['atipico'] = $mediana > 0
                && (float) $r->{$campo} > $mediana * self::FACTOR_ATIPICO;

            return $fila;
        }, $filas);
    }
}
