<?php

declare(strict_types=1);

namespace Tests\Unit\Epc;

use App\Domain\Tagging\Epc\EpcCodecFactory;
use App\Domain\Tagging\Epc\Gid96Codec;
use App\Domain\Tagging\Epc\Sgtin96Codec;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('epc')]
final class EpcCodecFactoryTest extends TestCase
{
    private EpcCodecFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new EpcCodecFactory;
    }

    public function test_entrega_el_codec_de_cada_esquema(): void
    {
        $this->assertInstanceOf(Sgtin96Codec::class, $this->factory->for('sgtin-96'));
        $this->assertInstanceOf(Gid96Codec::class, $this->factory->for('gid-96'));
    }

    public function test_rechaza_un_esquema_desconocido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->factory->for('epc-inventado');
    }

    public function test_usa_el_esquema_configurado_por_defecto(): void
    {
        config()->set('traza.epc.scheme', 'gid-96');
        $this->assertInstanceOf(Gid96Codec::class, $this->factory->default());

        config()->set('traza.epc.scheme', 'sgtin-96');
        $this->assertInstanceOf(Sgtin96Codec::class, $this->factory->default());
    }

    public function test_deduce_el_esquema_por_la_cabecera_del_epc(): void
    {
        // Lo que permite leer un tag antiguo sin saber cómo se codificó: es
        // la base de poder migrar de GID-96 a SGTIN-96 sin re-etiquetar.
        $sgtin = (new Sgtin96Codec)->encode('7751234', '012345', 7);
        $gid = (new Gid96Codec)->encode('12345', '678', 7);

        $this->assertSame('sgtin-96', $this->factory->decode($sgtin)['scheme']);
        $this->assertSame('gid-96', $this->factory->decode($gid)['scheme']);
    }

    public function test_rechaza_un_epc_de_cabecera_desconocida(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/No se reconoce el esquema/');
        $this->factory->decode('FF'.str_repeat('0', 22));
    }

    public function test_los_dos_esquemas_conviven(): void
    {
        // Escenario real de migración: inventario antiguo en GID y
        // etiquetas nuevas en SGTIN, leídos por el mismo sistema.
        $antiguos = array_map(
            fn (int $i) => (new Gid96Codec)->encode('12345', '678', $i),
            range(1, 5),
        );
        $nuevos = array_map(
            fn (int $i) => (new Sgtin96Codec)->encode('7751234', '012345', $i),
            range(1, 5),
        );

        foreach ([...$antiguos, ...$nuevos] as $epc) {
            $this->assertArrayHasKey('serial', $this->factory->decode($epc));
        }
    }
}
