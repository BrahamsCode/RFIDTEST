<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AlertKind;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo cerrado y alertas de ejemplo. Secciones 10 y 11 de `sql/seeds.sql`.
 *
 * Existe para que las pantallas de conciliación y de alertas tengan algo que
 * enseñar nada más levantar el entorno: una bandeja vacía no dice si la
 * pantalla funciona.
 */
final class OperationsSeeder extends Seeder
{
    public function run(): void
    {
        $organizationId = (int) DB::table('organizations')->orderBy('id')->value('id');
        $tienda = (int) DB::table('locations')->where('code', 'LIM-01')->value('id');
        $portal = (int) DB::table('devices')->where('code', 'PORT-LIM01')->value('id');

        $jefa = (int) DB::table('users')->where('email', 'jefe@ejemplo.pe')->value('id');
        $supervisor = (int) DB::table('users')->where('email', 'supervisor@ejemplo.pe')->value('id');

        $this->closedCycle($organizationId, $tienda, $jefa, $supervisor);
        $this->alerts($organizationId, $tienda, $portal);
    }

    private function closedCycle(int $organizationId, int $tienda, int $jefa, int $supervisor): void
    {
        if (DB::table('inventory_cycles')->where('code', 'INV-2026-08-031')->exists()) {
            return;
        }

        $expected = (int) DB::table('tags')
            ->where('current_location_id', $tienda)
            ->whereIn('state', ['en_stock', 'no_visto'])
            ->count();

        // 14 no encontradas de las esperadas: una exactitud de ~99 %, que es
        // lo que da un ciclo bueno. Un número redondo del 100 % haría que las
        // pantallas de conciliación pareciesen vacías.
        $missing = min(14, $expected);
        $found = $expected - $missing;

        DB::table('inventory_cycles')->insert([
            'organization_id' => $organizationId,
            'location_id' => $tienda,
            'code' => 'INV-2026-08-031',
            'scope' => 'total',
            'status' => 'cerrado',
            'started_by' => $jefa,
            'started_at' => now()->subDays(7),
            'closed_by' => $supervisor,
            'closed_at' => now()->subDays(7)->addMinutes(38),
            'expected_count' => $expected,
            'counted_count' => $found + 2,
            'found_count' => $found,
            'missing_count' => $missing,
            'unexpected_count' => 2,
            'accuracy_pct' => $expected === 0 ? null : round(100 * $found / $expected, 3),
            'notes' => 'Ciclo de ejemplo generado por la semilla.',
        ]);
    }

    private function alerts(int $organizationId, int $tienda, int $portal): void
    {
        if (DB::table('alerts')->where('detail->origen', 'seed')->exists()) {
            return;
        }

        $tagId = DB::table('tags')->where('state', 'en_stock')->orderBy('id')->value('id');

        $rows = [
            [
                'kind' => AlertKind::SalidaNoVendida->value,
                'severity' => 2,
                'tag_id' => $tagId,
                'device_id' => $portal,
                'title' => 'Prenda detectada saliendo sin registro de venta',
                'detail' => ['origen' => 'seed', 'confianza' => 0.86, 'direccion' => 'salida'],
                'triggered_at' => now()->subHours(2),
            ],
            [
                'kind' => AlertKind::EpcDesconocido->value,
                'severity' => 4,
                'tag_id' => null,
                'device_id' => $portal,
                'title' => 'EPC ajeno detectado repetidamente en el portal',
                'detail' => ['origen' => 'seed', 'epc' => 'AABBCCDDEEFF00112233445566', 'veces' => 47],
                'triggered_at' => now()->subDay(),
            ],
            [
                'kind' => AlertKind::TasaLecturaBaja->value,
                'severity' => 3,
                'tag_id' => null,
                'device_id' => null,
                'title' => 'La zona Probadores quedó por debajo del 60 % en el último ciclo',
                'detail' => ['origen' => 'seed', 'zona' => 'PROB', 'porcentaje' => 42.0],
                'triggered_at' => now()->subDays(7),
            ],
        ];

        DB::table('alerts')->insert(array_map(fn (array $a) => [
            'organization_id' => $organizationId,
            'location_id' => $tienda,
            'kind' => $a['kind'],
            'severity' => $a['severity'],
            'status' => 'abierta',
            'tag_id' => $a['tag_id'],
            'device_id' => $a['device_id'],
            'title' => $a['title'],
            'detail' => json_encode($a['detail']),
            'triggered_at' => $a['triggered_at'],
        ], $rows));
    }
}
