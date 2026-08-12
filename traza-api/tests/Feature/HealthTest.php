<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class HealthTest extends TestCase
{
    public function test_expone_el_estado_y_el_esquema_epc_configurado(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertJsonStructure(['status', 'version', 'epc_scheme', 'checks']);
        $this->assertSame(config('traza.epc.scheme'), $response->json('epc_scheme'));
    }

    public function test_la_ruta_de_usuario_exige_autenticacion(): void
    {
        $this->getJson('/api/v1/user')->assertUnauthorized();
    }
}
