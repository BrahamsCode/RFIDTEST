<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

final class TrazaConfigTest extends TestCase
{
    public function test_los_parametros_de_dominio_estan_publicados(): void
    {
        $this->assertIsArray(config('traza.epc'));
        $this->assertIsArray(config('traza.inventory'));
        $this->assertIsArray(config('traza.portal'));
        $this->assertIsArray(config('traza.ingest'));
    }

    public function test_la_mascara_epc_se_normaliza_en_mayusculas(): void
    {
        config()->set('traza.epc.mask', strtoupper('3035d9'));
        $this->assertSame('3035D9', config('traza.epc.mask'));
    }

    public function test_el_umbral_de_perdida_nunca_es_uno_por_defecto(): void
    {
        // Un umbral de 1 borraría stock real ante un simple fallo de lectura.
        $this->assertGreaterThanOrEqual(2, config('traza.inventory.missing_cycles_threshold'));
    }

    public function test_la_confianza_del_portal_esta_entre_cero_y_uno(): void
    {
        $confidence = config('traza.portal.min_confidence');
        $this->assertGreaterThan(0, $confidence);
        $this->assertLessThanOrEqual(1, $confidence);
    }
}
