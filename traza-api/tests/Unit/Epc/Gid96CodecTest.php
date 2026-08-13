<?php

declare(strict_types=1);

namespace Tests\Unit\Epc;

use App\Domain\Tagging\Epc\Gid96Codec;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('epc')]
final class Gid96CodecTest extends TestCase
{
    private Gid96Codec $codec;

    protected function setUp(): void
    {
        $this->codec = new Gid96Codec();
    }

    public function test_codifica_con_la_cabecera_de_gid96(): void
    {
        $epc = $this->codec->encode('12345', '678', 42);

        $this->assertSame(24, strlen($epc));
        $this->assertStringStartsWith('35', $epc);
    }

    public function test_recorre_ida_y_vuelta(): void
    {
        $decoded = $this->codec->decode($this->codec->encode('268435455', '16777215', 68719476735));

        $this->assertSame('gid-96', $decoded['scheme']);
        $this->assertSame(268435455, $decoded['managerNumber']);
        $this->assertSame(16777215, $decoded['objectClass']);
        $this->assertSame(68719476735, $decoded['serial']);
    }

    public function test_acepta_todos_los_campos_a_cero(): void
    {
        $epc = $this->codec->encode('0', '0', 0);

        $this->assertSame('35'.str_repeat('0', 22), $epc);
        $this->assertSame(0, $this->codec->decode($epc)['serial']);
    }

    public function test_rechaza_un_manager_fuera_de_28_bits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/28 bits/');
        $this->codec->encode((string) (Gid96Codec::MANAGER_MAX + 1), '1', 1);
    }

    public function test_rechaza_un_object_class_fuera_de_24_bits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/24 bits/');
        $this->codec->encode('1', (string) (Gid96Codec::CLASS_MAX + 1), 1);
    }

    public function test_rechaza_un_serial_fuera_de_36_bits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/36 bits/');
        $this->codec->encode('1', '1', Gid96Codec::SERIAL_MAX + 1);
    }

    public function test_admite_mas_serial_que_sgtin(): void
    {
        // 36 bits de GID contra 38 de SGTIN: menos serial, pero más object
        // class. Es la contrapartida de no necesitar GS1.
        $this->assertSame(68719476735, $this->codec->maxSerial());
    }

    public function test_rechaza_un_epc_de_otro_esquema(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no es GID-96/');
        $this->codec->decode('3035D919080C0E403B9ACA2A');
    }

    public function test_mantiene_la_invariante_con_entradas_aleatorias(): void
    {
        foreach (range(1, 300) as $_) {
            $manager = (string) random_int(0, Gid96Codec::MANAGER_MAX);
            $class = (string) random_int(0, Gid96Codec::CLASS_MAX);
            $serial = random_int(0, Gid96Codec::SERIAL_MAX);

            $decoded = $this->codec->decode($this->codec->encode($manager, $class, $serial));

            $this->assertSame((int) $manager, $decoded['managerNumber']);
            $this->assertSame((int) $class, $decoded['objectClass']);
            $this->assertSame($serial, $decoded['serial']);
        }
    }
}
