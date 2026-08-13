<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Tagging\TagStateMachine;
use App\Enums\MovementType;
use App\Enums\TagState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TagStateMachineTest extends TestCase
{
    private TagStateMachine $machine;

    protected function setUp(): void
    {
        $this->machine = new TagStateMachine;
    }

    /**
     * Matriz explícita de las 100 combinaciones estado×estado. Se escribe a
     * mano y no derivándola de la propia clase: una prueba que se calcula
     * desde el código que prueba no detecta que ese código cambió.
     *
     * @return array<string, array{TagState, TagState, bool}>
     */
    public static function transitions(): array
    {
        $allowed = [
            'creado' => ['codificado', 'anulado'],
            'codificado' => ['en_stock', 'anulado'],
            'en_stock' => ['vendido', 'en_transito', 'no_visto', 'danado', 'baja', 'en_stock'],
            'en_transito' => ['en_stock', 'perdido'],
            'no_visto' => ['en_stock', 'perdido', 'vendido'],
            'perdido' => ['en_stock'],
            'vendido' => ['en_stock'],
            'danado' => ['baja'],
            'baja' => [],
            'anulado' => [],
        ];

        $cases = [];
        foreach (TagState::cases() as $from) {
            foreach (TagState::cases() as $to) {
                $expected = in_array($to->value, $allowed[$from->value], strict: true);
                $cases["{$from->value} → {$to->value}"] = [$from, $to, $expected];
            }
        }

        return $cases;
    }

    #[DataProvider('transitions')]
    public function test_la_matriz_de_transiciones_es_la_documentada(
        TagState $from,
        TagState $to,
        bool $expected,
    ): void {
        $this->assertSame($expected, $this->machine->canTransition($from, $to));
    }

    public function test_cubre_exactamente_las_100_combinaciones(): void
    {
        $this->assertCount(100, self::transitions());
        $this->assertCount(10, TagState::cases());
    }

    public function test_los_estados_terminales_no_admiten_salida(): void
    {
        foreach ([TagState::Baja, TagState::Anulado] as $terminal) {
            $this->assertTrue($terminal->isTerminal());
            $this->assertSame([], $this->machine->allowedFrom($terminal));
        }
    }

    public function test_ningun_otro_estado_es_terminal(): void
    {
        foreach (TagState::cases() as $state) {
            if ($state->isTerminal()) {
                continue;
            }
            $this->assertNotEmpty(
                $this->machine->allowedFrom($state),
                "El estado {$state->value} no es terminal pero no tiene salidas."
            );
        }
    }

    /** @return array<string, array{MovementType, TagState}> */
    public static function movementTargets(): array
    {
        return [
            'tarado' => [MovementType::Tarado, TagState::EnStock],
            'recepcion' => [MovementType::Recepcion, TagState::EnStock],
            'venta' => [MovementType::Venta, TagState::Vendido],
            'devolucion_cliente' => [MovementType::DevolucionCliente, TagState::EnStock],
            'devolucion_prov' => [MovementType::DevolucionProveedor, TagState::Baja],
            'transferencia_out' => [MovementType::TransferenciaOut, TagState::EnTransito],
            'transferencia_in' => [MovementType::TransferenciaIn, TagState::EnStock],
            'ajuste_positivo' => [MovementType::AjustePositivo, TagState::EnStock],
            'ajuste_negativo' => [MovementType::AjusteNegativo, TagState::NoVisto],
            'merma' => [MovementType::Merma, TagState::Perdido],
            'dano' => [MovementType::Dano, TagState::Danado],
            'cambio_zona' => [MovementType::CambioZona, TagState::EnStock],
            'reetiquetado' => [MovementType::Reetiquetado, TagState::Baja],
            'anulacion' => [MovementType::Anulacion, TagState::Anulado],
        ];
    }

    #[DataProvider('movementTargets')]
    public function test_cada_movimiento_resuelve_a_su_estado_destino(
        MovementType $type,
        TagState $expected,
    ): void {
        $this->assertSame($expected, $this->machine->resolve(TagState::Creado, $type));
    }

    public function test_los_14_tipos_de_movimiento_tienen_destino(): void
    {
        $this->assertCount(14, MovementType::cases());

        foreach (MovementType::cases() as $type) {
            // No debe lanzar para ninguno.
            $this->machine->resolve(TagState::EnStock, $type);
        }

        $this->addToAssertionCount(1);
    }

    public function test_cambiar_de_zona_mantiene_la_prenda_en_stock(): void
    {
        // Sin la auto-transición en_stock → en_stock, mover una prenda de la
        // trastienda a la sala fallaría.
        $target = $this->machine->resolve(TagState::EnStock, MovementType::CambioZona);

        $this->assertSame(TagState::EnStock, $target);
        $this->assertTrue($this->machine->canTransition(TagState::EnStock, $target));
    }

    public function test_una_prenda_no_vista_todavia_se_puede_vender(): void
    {
        // Estaba en la tienda; el ciclo simplemente no la leyó. Bloquear la
        // venta sería rechazar una operación legítima en caja.
        $target = $this->machine->resolve(TagState::NoVisto, MovementType::Venta);

        $this->assertTrue($this->machine->canTransition(TagState::NoVisto, $target));
    }

    public function test_una_prenda_perdida_no_se_puede_vender_directamente(): void
    {
        // Primero tiene que reaparecer y generar su alerta.
        $this->assertFalse($this->machine->canTransition(TagState::Perdido, TagState::Vendido));
    }

    public function test_detecta_la_reaparicion_de_una_prenda_dada_por_perdida(): void
    {
        $this->assertTrue(
            $this->machine->isReappearance(TagState::Perdido, TagState::EnStock)
        );
        $this->assertFalse(
            $this->machine->isReappearance(TagState::NoVisto, TagState::EnStock)
        );
    }

    public function test_no_se_puede_dar_de_baja_un_epc_ya_anulado(): void
    {
        $this->assertFalse($this->machine->canTransition(TagState::Anulado, TagState::Baja));
        $this->assertFalse($this->machine->canTransition(TagState::Anulado, TagState::EnStock));
    }

    public function test_solo_en_stock_y_no_visto_cuentan_como_existencias(): void
    {
        foreach (TagState::cases() as $state) {
            $expected = in_array($state, [TagState::EnStock, TagState::NoVisto], strict: true);
            $this->assertSame($expected, $state->countsAsStock(), $state->value);
        }
    }

    public function test_el_signo_del_movimiento_refleja_entrada_o_salida(): void
    {
        $this->assertSame(1, MovementType::Recepcion->sign());
        $this->assertSame(-1, MovementType::Venta->sign());
        // Cambiar de zona no altera el total de la ubicación.
        $this->assertSame(0, MovementType::CambioZona->sign());
    }

    public function test_los_ajustes_manuales_y_la_merma_exigen_aprobacion(): void
    {
        $this->assertTrue(MovementType::AjustePositivo->requiresApproval());
        $this->assertTrue(MovementType::AjusteNegativo->requiresApproval());
        $this->assertTrue(MovementType::Merma->requiresApproval());
        $this->assertFalse(MovementType::Venta->requiresApproval());
    }
}
