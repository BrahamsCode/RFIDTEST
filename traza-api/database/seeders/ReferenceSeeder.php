<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Datos de referencia: organización, tiendas, zonas, perfiles de lectura,
 * dispositivos y catálogo. Equivale a las secciones 1 a 7 de `sql/seeds.sql`.
 *
 * Se usa `upsert` por código en todo: `db:seed` tiene que poder ejecutarse
 * dos veces seguidas sin reventar por clave única, que es lo que uno hace en
 * cuanto añade una variante nueva y quiere verla.
 */
final class ReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $organizationId = $this->organization();
        $locations = $this->locations($organizationId);
        $zones = $this->zones($locations);

        $this->readProfiles($organizationId);
        $this->devices($organizationId, $locations, $zones);
        $this->catalog($organizationId);
        $this->users($organizationId, $locations);
    }

    private function organization(): int
    {
        // `organizations` no tiene índice único sobre `tax_id`, así que aquí
        // no cabe un `upsert`: se busca y se crea si falta.
        $existing = DB::table('organizations')->where('tax_id', '20123456789')->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('organizations')->insertGetId([
            'name' => 'Comercial Ejemplo S.A.C.',
            'tax_id' => '20123456789',
            'gs1_company_prefix' => '7751234',
            'default_epc_scheme' => 'sgtin-96',
            'epc_filter_mask' => '3035D9',
            'timezone' => 'America/Lima',
            'settings' => json_encode(['moneda' => 'PEN', 'igv' => 0.18]),
        ]);
    }

    /** @return array<string, int> código → id */
    private function locations(int $organizationId): array
    {
        $rows = [
            ['LIM-01', 'Tienda Gamarra 1', 'tienda', 'Jr. Gamarra 123, La Victoria, Lima'],
            ['LIM-02', 'Tienda Gamarra 2', 'tienda', 'Jr. Antonio Bazo 456, La Victoria'],
            ['CEN-01', 'Almacén central', 'almacen', 'Av. Aviación 1200, Ate, Lima'],
            ['TRA-01', 'En tránsito', 'transito', null],
        ];

        DB::table('locations')->upsert(
            array_map(fn (array $r) => [
                'organization_id' => $organizationId,
                'code' => $r[0], 'name' => $r[1], 'kind' => $r[2], 'address' => $r[3],
                'is_active' => true,
            ], $rows),
            ['organization_id', 'code'],
            ['name', 'kind', 'address'],
        );

        return DB::table('locations')
            ->where('organization_id', $organizationId)
            ->pluck('id', 'code')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  array<string, int>  $locations
     * @return array<string, int> "LIM-01:SALA" → id
     */
    private function zones(array $locations): array
    {
        $byLocation = [
            'LIM-01' => [
                ['ESC', 'Escaparate', 'escaparate', true, 1],
                ['SALA', 'Sala principal', 'sala', true, 2],
                ['PROB', 'Probadores', 'probador', true, 3],
                ['CAJA', 'Punto de caja', 'caja', true, 4],
                ['TRA-A', 'Trastienda A', 'trastienda', false, 5],
                ['TRA-B', 'Trastienda B', 'trastienda', false, 6],
                ['SAL', 'Salida', 'salida', false, 7],
            ],
            'LIM-02' => [
                ['SALA', 'Sala principal', 'sala', true, 1],
                ['PROB', 'Probadores', 'probador', true, 2],
                ['TRA', 'Trastienda', 'trastienda', false, 3],
                ['SAL', 'Salida', 'salida', false, 4],
            ],
            'CEN-01' => [
                ['REC', 'Recepción', 'recepcion', false, 1],
                ['STK', 'Estantería stock', 'trastienda', false, 2],
                ['ETQ', 'Etiquetado', 'otro', false, 3],
            ],
        ];

        $rows = [];
        foreach ($byLocation as $locationCode => $zones) {
            foreach ($zones as $z) {
                $rows[] = [
                    'location_id' => $locations[$locationCode],
                    'code' => $z[0], 'name' => $z[1], 'kind' => $z[2],
                    'counts_as_sellable' => $z[3], 'sort_order' => $z[4],
                ];
            }
        }

        DB::table('zones')->upsert($rows, ['location_id', 'code'], ['name', 'kind', 'counts_as_sellable']);

        $out = [];
        foreach (DB::table('zones as z')->join('locations as l', 'l.id', '=', 'z.location_id')
            ->select('z.id', 'z.code', 'l.code as location_code')->get() as $row) {
            $out["{$row->location_code}:{$row->code}"] = (int) $row->id;
        }

        return $out;
    }

    private function readProfiles(int $organizationId): void
    {
        $rows = [
            ['INV-SALA', 'Inventario en sala', 2, 'A', 6, 28.0, 2000, 1, -70.0, false,
                'S2 evita releer lo ya contado durante el barrido.'],
            ['INV-ALM', 'Inventario en almacén', 2, 'A', 7, 30.0, 2000, 1, -72.0, false,
                'Población más densa: Q más alto y potencia máxima.'],
            ['TARADO', 'Tarado individual', 0, 'A', 2, 16.0, 500, 3, -45.0, true,
                'POTENCIA BAJA A PROPÓSITO: solo debe leer la prenda en la mano. '
                .'Lee TID para el testigo antifraude. Ver docs/09 §4.'],
            ['PORTAL', 'Portal de salida', 0, 'A', 4, 26.0, 200, 2, -62.0, false,
                'S0 para que el tag reporte repetidamente mientras cruza.'],
            ['TUNEL', 'Túnel de recepción', 1, 'A', 6, 27.0, 1000, 2, -65.0, true,
                'S1 con Q ajustado al tamaño del bulto.'],
            ['CAJA', 'Antena de campo cercano en caja', 0, 'A', 3, 12.0, 300, 2, -40.0, false,
                'Umbral de RSSI muy alto: solo lo que está sobre el mostrador.'],
            ['BUSQUEDA', 'Modo Geiger', 0, 'A', 2, 20.0, 100, 1, null, false,
                'Sin umbral: la proximidad se infiere del RSSI crudo.'],
        ];

        DB::table('read_profiles')->upsert(
            array_map(fn (array $r) => [
                'organization_id' => $organizationId,
                'code' => $r[0], 'name' => $r[1], 'session' => $r[2], 'target' => $r[3],
                'initial_q' => $r[4], 'tx_power_dbm' => $r[5], 'dedup_window_ms' => $r[6],
                'min_read_count' => $r[7], 'rssi_threshold' => $r[8], 'read_tid' => $r[9],
                'notes' => $r[10],
            ], $rows),
            ['organization_id', 'code'],
            ['name', 'session', 'tx_power_dbm', 'rssi_threshold', 'notes'],
        );
    }

    /**
     * @param  array<string, int>  $locations
     * @param  array<string, int>  $zones
     */
    private function devices(int $organizationId, array $locations, array $zones): void
    {
        $rows = [
            ['EDGE-LIM01', 'Borde tienda 1', 'edge', 'LIM-01', 'Intel', 'NUC N100', '192.168.10.10', []],
            ['HH-LIM01-01', 'Handheld tienda 1 #1', 'handheld', 'LIM-01', 'Zebra', 'RFD40+TC22', null, ['profile_default' => 'INV-SALA']],
            ['HH-LIM01-02', 'Handheld tienda 1 #2', 'handheld', 'LIM-01', 'Chainway', 'C72', null, ['profile_default' => 'INV-SALA']],
            ['PORT-LIM01', 'Portal salida tienda 1', 'lector_fijo', 'LIM-01', 'Zebra', 'FX9600', '192.168.10.21', ['profile' => 'PORTAL']],
            ['TUN-CEN01', 'Túnel de recepción', 'lector_fijo', 'CEN-01', 'Chainway', 'UR4', '192.168.20.22', ['profile' => 'TUNEL']],
            ['PRN-CEN01', 'Impresora RFID', 'impresora', 'CEN-01', 'Zebra', 'ZT411 RFID', '192.168.20.31', ['dpi' => 203, 'label_mm' => '50x30']],
        ];

        DB::table('devices')->upsert(
            array_map(fn (array $r) => [
                'organization_id' => $organizationId,
                'location_id' => $locations[$r[3]],
                'code' => $r[0], 'name' => $r[1], 'kind' => $r[2],
                'manufacturer' => $r[4], 'model' => $r[5], 'ip_address' => $r[6],
                'regulatory_region' => 'FCC-PE', 'status' => 'activo',
                'settings' => json_encode($r[7]),
            ], $rows),
            ['organization_id', 'code'],
            ['name', 'kind', 'manufacturer', 'model', 'ip_address', 'settings'],
        );

        $portalId = (int) DB::table('devices')->where('code', 'PORT-LIM01')->value('id');
        $tunnelId = (int) DB::table('devices')->where('code', 'TUN-CEN01')->value('id');

        /*
         * La geometría de las antenas del portal no es decorativa: el lado
         * interior/exterior es lo que usa `DirectionStage` para clasificar un
         * cruce. Sin esto, el borde no sabe distinguir una salida de una
         * entrada y el antihurto no funciona.
         */
        DB::table('device_antennas')->upsert([
            ['device_id' => $portalId, 'zone_id' => $zones['LIM-01:SAL'], 'port_number' => 1,
                'label' => 'Portal izq. alta', 'mount_height_cm' => 160, 'tilt_degrees' => 12,
                'side' => 'interior', 'tx_power_dbm' => 26.0, 'rssi_threshold' => -62.0, 'is_enabled' => true],
            ['device_id' => $portalId, 'zone_id' => $zones['LIM-01:SAL'], 'port_number' => 2,
                'label' => 'Portal izq. baja', 'mount_height_cm' => 60, 'tilt_degrees' => 12,
                'side' => 'interior', 'tx_power_dbm' => 26.0, 'rssi_threshold' => -62.0, 'is_enabled' => true],
            ['device_id' => $portalId, 'zone_id' => $zones['LIM-01:SAL'], 'port_number' => 3,
                'label' => 'Portal der. alta', 'mount_height_cm' => 160, 'tilt_degrees' => 12,
                'side' => 'exterior', 'tx_power_dbm' => 26.0, 'rssi_threshold' => -62.0, 'is_enabled' => true],
            ['device_id' => $portalId, 'zone_id' => $zones['LIM-01:SAL'], 'port_number' => 4,
                'label' => 'Portal der. baja', 'mount_height_cm' => 60, 'tilt_degrees' => 12,
                'side' => 'exterior', 'tx_power_dbm' => 26.0, 'rssi_threshold' => -62.0, 'is_enabled' => true],
        ], ['device_id', 'port_number'], ['label', 'side', 'tx_power_dbm', 'rssi_threshold']);

        DB::table('device_antennas')->upsert(
            array_map(fn (int $port, string $label) => [
                'device_id' => $tunnelId, 'zone_id' => $zones['CEN-01:REC'], 'port_number' => $port,
                'label' => $label, 'tx_power_dbm' => 27.0, 'rssi_threshold' => -65.0, 'is_enabled' => true,
            ], [1, 2, 3, 4], ['Túnel superior', 'Túnel inferior', 'Túnel lateral A', 'Túnel lateral B']),
            ['device_id', 'port_number'],
            ['label', 'tx_power_dbm'],
        );
    }

    private function catalog(int $organizationId): void
    {
        DB::table('suppliers')->upsert([
            ['organization_id' => $organizationId, 'code' => 'PRV-001', 'name' => 'Textiles Andinos S.A.C.', 'tax_id' => '20111222333', 'is_active' => true],
            ['organization_id' => $organizationId, 'code' => 'PRV-002', 'name' => 'Confecciones Gamarra EIRL', 'tax_id' => '20444555666', 'is_active' => true],
            ['organization_id' => $organizationId, 'code' => 'PRV-003', 'name' => 'Denim Import S.A.', 'tax_id' => '20777888999', 'is_active' => true],
        ], ['organization_id', 'code'], ['name', 'tax_id']);

        foreach ([['MUJ', 'Mujer', '/mujer', null], ['HOM', 'Hombre', '/hombre', null]] as $c) {
            DB::table('categories')->upsert([[
                'organization_id' => $organizationId, 'parent_id' => null,
                'code' => $c[0], 'name' => $c[1], 'path' => $c[2],
            ]], ['organization_id', 'code'], ['name', 'path']);
        }

        $categories = DB::table('categories')->where('organization_id', $organizationId)
            ->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();

        DB::table('categories')->upsert([
            ['organization_id' => $organizationId, 'parent_id' => $categories['MUJ'], 'code' => 'MUJ-SUP', 'name' => 'Superiores mujer', 'path' => '/mujer/superiores'],
            ['organization_id' => $organizationId, 'parent_id' => $categories['MUJ'], 'code' => 'MUJ-INF', 'name' => 'Inferiores mujer', 'path' => '/mujer/inferiores'],
            ['organization_id' => $organizationId, 'parent_id' => $categories['HOM'], 'code' => 'HOM-SUP', 'name' => 'Superiores hombre', 'path' => '/hombre/superiores'],
            ['organization_id' => $organizationId, 'parent_id' => $categories['HOM'], 'code' => 'HOM-INF', 'name' => 'Inferiores hombre', 'path' => '/hombre/inferiores'],
        ], ['organization_id', 'code'], ['name', 'path', 'parent_id']);

        DB::table('seasons')->upsert([
            ['organization_id' => $organizationId, 'code' => 'V26', 'name' => 'Verano 2026', 'starts_on' => '2025-11-01', 'ends_on' => '2026-03-31'],
            ['organization_id' => $organizationId, 'code' => 'I26', 'name' => 'Invierno 2026', 'starts_on' => '2026-04-01', 'ends_on' => '2026-09-30'],
        ], ['organization_id', 'code'], ['name', 'starts_on', 'ends_on']);

        $categories = DB::table('categories')->where('organization_id', $organizationId)
            ->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();
        $seasons = DB::table('seasons')->where('organization_id', $organizationId)
            ->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();
        $suppliers = DB::table('suppliers')->where('organization_id', $organizationId)
            ->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();

        // rfid_difficulty: 1 fácil (algodón), 5 difícil (metálico). El top con
        // lentejuelas está en 5 a propósito: es la prenda que va a fallar en
        // la prueba de campo, y conviene tenerla en los datos de desarrollo.
        $products = [
            ['POL-OVER', 'Polera Oversize', 'MUJ-SUP', 'PRV-001', '100% algodón', 1],
            ['BLU-LINO', 'Blusa de Lino', 'MUJ-SUP', 'PRV-002', '55% lino, 45% viscosa', 1],
            ['JEA-SLIM', 'Jean Slim', 'MUJ-INF', 'PRV-003', '98% algodón, 2% elastano', 3],
            ['CAM-BASIC', 'Camiseta Básica', 'HOM-SUP', 'PRV-001', '100% algodón', 1],
            ['JEA-RECTO', 'Jean Recto Hombre', 'HOM-INF', 'PRV-003', '100% algodón', 3],
            ['TOP-LENT', 'Top con Lentejuelas', 'MUJ-SUP', 'PRV-002', 'poliéster con aplicación metálica', 5],
        ];

        DB::table('products')->upsert(
            array_map(fn (array $p) => [
                'organization_id' => $organizationId,
                'category_id' => $categories[$p[2]],
                'season_id' => $seasons['I26'],
                'supplier_id' => $suppliers[$p[3]],
                'code' => $p[0], 'name' => $p[1], 'brand' => 'Ejemplo',
                'composition' => $p[4], 'rfid_difficulty' => $p[5], 'is_active' => true,
            ], $products),
            ['organization_id', 'code'],
            ['name', 'composition', 'rfid_difficulty', 'category_id'],
        );

        $productIds = DB::table('products')->where('organization_id', $organizationId)
            ->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();

        // item_reference de 6 dígitos: partición 5 del SGTIN-96 con un prefijo
        // GS1 de 7. Ver `docs/04` §2.1.
        $variants = [
            ['POL-OVER', 'POL-OVER-M-NEG', 'M', 'Negro', '#111111', '7751234123456', '012345', 32.00, 89.90, 2],
            ['POL-OVER', 'POL-OVER-L-NEG', 'L', 'Negro', '#111111', '7751234123463', '012346', 32.00, 89.90, 2],
            ['POL-OVER', 'POL-OVER-M-BLA', 'M', 'Blanco', '#FFFFFF', '7751234123470', '012347', 32.00, 89.90, 2],
            ['BLU-LINO', 'BLU-LINO-S-BEI', 'S', 'Beige', '#D8C9A9', '7751234123487', '012348', 41.00, 119.90, 1],
            ['BLU-LINO', 'BLU-LINO-M-BEI', 'M', 'Beige', '#D8C9A9', '7751234123494', '012349', 41.00, 119.90, 1],
            ['JEA-SLIM', 'JEA-SLIM-28-AZU', '28', 'Azul', '#2B4C7E', '7751234123500', '012350', 58.00, 159.90, 2],
            ['JEA-SLIM', 'JEA-SLIM-30-AZU', '30', 'Azul', '#2B4C7E', '7751234123517', '012351', 58.00, 159.90, 3],
            ['JEA-SLIM', 'JEA-SLIM-32-AZU', '32', 'Azul', '#2B4C7E', '7751234123524', '012352', 58.00, 159.90, 3],
            ['CAM-BASIC', 'CAM-BASIC-M-GRI', 'M', 'Gris', '#8A8A8A', '7751234123531', '012353', 18.00, 49.90, 4],
            ['CAM-BASIC', 'CAM-BASIC-L-GRI', 'L', 'Gris', '#8A8A8A', '7751234123548', '012354', 18.00, 49.90, 4],
            ['JEA-RECTO', 'JEA-RECT-32-AZU', '32', 'Azul', '#31527F', '7751234123555', '012355', 62.00, 169.90, 2],
            ['TOP-LENT', 'TOP-LENT-S-DOR', 'S', 'Dorado', '#C9A227', '7751234123562', '012356', 45.00, 139.90, 1],
        ];

        DB::table('product_variants')->upsert(
            array_map(fn (array $v) => [
                'product_id' => $productIds[$v[0]],
                'sku' => $v[1], 'size' => $v[2], 'color' => $v[3], 'color_hex' => $v[4],
                'barcode' => $v[5], 'gtin13' => $v[5], 'item_reference' => $v[6],
                'cost_price' => $v[7], 'sale_price' => $v[8], 'currency' => 'PEN',
                'min_stock' => $v[9], 'is_active' => true,
            ], $variants),
            ['sku'],
            ['size', 'color', 'cost_price', 'sale_price', 'min_stock'],
        );
    }

    /** @param array<string, int> $locations */
    private function users(int $organizationId, array $locations): void
    {
        $rows = [
            ['admin@ejemplo.pe', 'Administrador', 'admin', 'LIM-01'],
            ['jefe@ejemplo.pe', 'Jefa de tienda', 'jefe_tienda', 'LIM-01'],
            ['almacen@ejemplo.pe', 'Encargado de almacén', 'almacen', 'CEN-01'],
            ['supervisor@ejemplo.pe', 'Supervisor regional', 'supervisor_regional', 'LIM-01'],
            ['vendedor@ejemplo.pe', 'Vendedora', 'vendedor', 'LIM-01'],
            ['tecnico@ejemplo.pe', 'Técnico', 'tecnico', 'LIM-01'],
            ['gerencia@ejemplo.pe', 'Gerencia', 'gerencia', 'LIM-01'],
        ];

        // Contraseña de todos: "password". Solo desarrollo; en producción los
        // usuarios se crean desde la web.
        $hash = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

        DB::table('users')->upsert(
            array_map(fn (array $u) => [
                'organization_id' => $organizationId,
                'email' => $u[0], 'name' => $u[1], 'password' => $hash,
                'default_location_id' => $locations[$u[3]], 'is_active' => true,
            ], $rows),
            ['email'],
            ['name', 'default_location_id'],
        );

        $userIds = DB::table('users')->whereIn('email', array_column($rows, 0))
            ->pluck('id', 'email')->map(fn ($id) => (int) $id)->all();
        $roleIds = DB::table('roles')->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();

        foreach ($rows as $u) {
            if (! isset($roleIds[$u[2]])) {
                continue;
            }

            // La tabla pivote tampoco tiene índice único; `updateOrInsert`
            // evita que `db:seed` dos veces duplique los roles.
            DB::table('role_user')->updateOrInsert(
                ['user_id' => $userIds[$u[0]], 'role_id' => $roleIds[$u[2]]],
                [],
            );
        }
    }
}
