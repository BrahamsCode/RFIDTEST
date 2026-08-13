<?php

declare(strict_types=1);

namespace Tests\Unit\Epc;

use App\Domain\Tagging\Epc\Sgtin96Codec;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * El grupo `epc` se ejecuta como tarea separada en CI para que su fallo sea
 * inconfundible: un error aquí corrompe identificadores de forma silenciosa
 * e irreversible.
 */
#[Group('epc')]
final class Sgtin96CodecTest extends TestCase
{
    private Sgtin96Codec $codec;

    protected function setUp(): void
    {
        $this->codec = new Sgtin96Codec();
    }

    public function test_coincide_con_el_ejemplo_de_referencia_de_la_documentacion(): void
    {
        $this->assertSame(
            '3035D919080C0E403B9ACA2A',
            $this->codec->encode('7751234', '012345', 1000000042),
        );
    }

    /** @return array<string, array{int}> */
    public static function partitions(): array
    {
        return array_combine(
            array_map(static fn (int $p) => "partición {$p}", range(0, 6)),
            array_map(static fn (int $p) => [$p], range(0, 6)),
        );
    }

    #[DataProvider('partitions')]
    public function test_recorre_ida_y_vuelta_para_todas_las_particiones(int $partition): void
    {
        [, $cpDigits, , $irDigits] = Sgtin96Codec::partitionSpec($partition);

        $companyPrefix = str_pad('7', $cpDigits, '5');
        $itemReference = str_pad('0', $irDigits, '3');
        $serial = 987654321;

        $epc = $this->codec->encode($companyPrefix, $itemReference, $serial);
        $decoded = $this->codec->decode($epc);

        $this->assertSame(24, strlen($epc));
        $this->assertSame($companyPrefix, $decoded['companyPrefix']);
        $this->assertSame($itemReference, $decoded['itemReference']);
        $this->assertSame($serial, $decoded['serial']);
        $this->assertSame($partition, $decoded['partition']);
    }

    public function test_acepta_el_serial_en_los_dos_extremos_del_rango(): void
    {
        foreach ([0, Sgtin96Codec::SERIAL_MAX] as $serial) {
            $decoded = $this->codec->decode($this->codec->encode('7751234', '012345', $serial));
            $this->assertSame($serial, $decoded['serial']);
        }
    }

    public function test_rechaza_un_serial_que_desborda_38_bits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->codec->encode('7751234', '012345', 2 ** 38);
    }

    public function test_rechaza_un_serial_negativo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->codec->encode('7751234', '012345', -1);
    }

    public function test_rechaza_una_referencia_con_los_digitos_equivocados(): void
    {
        // La partición 5 exige 6 dígitos de referencia.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/6 dígitos/');
        $this->codec->encode('7751234', '12345', 1);
    }

    public function test_rechaza_un_prefijo_sin_particion(): void
    {
        // 13 dígitos no corresponde a ninguna partición.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/No hay partición/');
        $this->codec->encode('7751234567890', '1', 1);
    }

    public function test_rechaza_entradas_que_no_son_digitos(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->codec->encode('775ABCD', '012345', 1);
    }

    public function test_rechaza_un_filter_fuera_de_rango(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->codec->encode('7751234', '012345', 1, filter: 8);
    }

    /** @return array<string, array{string}> */
    public static function malformed(): array
    {
        return [
            'demasiado corto' => ['3035D9'],
            'demasiado largo' => ['3035D919080C0E403B9ACA2AFF'],
            'con caracteres no hexadecimales' => ['3035D919080C0E403B9ACAZZ'],
            'vacío' => [''],
        ];
    }

    #[DataProvider('malformed')]
    public function test_rechaza_epc_malformados(string $epc): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->codec->decode($epc);
    }

    public function test_rechaza_un_epc_de_otro_esquema(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no es SGTIN-96/');
        // Cabecera 0x35: es un GID-96.
        $this->codec->decode('35'.str_repeat('0', 22));
    }

    public function test_conserva_el_filter(): void
    {
        $decoded = $this->codec->decode($this->codec->encode('7751234', '012345', 42, filter: 3));
        $this->assertSame(3, $decoded['filter']);
    }

    public function test_calcula_el_digito_de_control_del_gtin(): void
    {
        // EAN-13 conocidos: 4006381333931 y 5901234123457.
        $this->assertSame('1', $this->codec->checkDigit('400638133393'));
        $this->assertSame('7', $this->codec->checkDigit('590123412345'));
    }

    public function test_deriva_el_gtin_del_epc(): void
    {
        $decoded = $this->codec->decode($this->codec->encode('7751234', '012345', 1));

        // Indicador 0 + prefijo 7751234 + resto 12345 + control = 14 dígitos.
        $this->assertSame('07751234123456', $decoded['gtin14']);
        // Con indicador 0 hay EAN-13 equivalente: el mismo sin el cero.
        $this->assertSame('7751234123456', $decoded['gtin13']);
    }

    public function test_no_hay_ean13_cuando_el_indicador_no_es_cero(): void
    {
        // Indicador 1 es un agrupamiento (caja), no una unidad de consumo.
        $decoded = $this->codec->decode($this->codec->encode('7751234', '112345', 1));

        $this->assertSame(14, strlen($decoded['gtin14']));
        $this->assertNull($decoded['gtin13'], 'No debe inventarse un EAN-13.');
    }

    public function test_el_gtin_es_valido_en_todas_las_particiones(): void
    {
        foreach (range(0, 6) as $partition) {
            [, $cpDigits, , $irDigits] = Sgtin96Codec::partitionSpec($partition);
            $cp = str_pad('7', $cpDigits, '5');
            $ir = str_pad('0', $irDigits, '3');

            $gtin14 = $this->codec->decode($this->codec->encode($cp, $ir, 1))['gtin14'];

            $this->assertSame(14, strlen($gtin14), "Partición {$partition}.");
            $this->assertSame(
                $this->codec->checkDigit(substr($gtin14, 0, 13)),
                substr($gtin14, -1),
                "Dígito de control incorrecto en la partición {$partition}.",
            );
        }
    }

    public function test_no_distingue_mayusculas_al_decodificar(): void
    {
        $this->assertSame(
            $this->codec->decode('3035D919080C0E403B9ACA2A'),
            $this->codec->decode('3035d919080c0e403b9aca2a'),
        );
    }

    public function test_mantiene_la_invariante_con_entradas_aleatorias(): void
    {
        foreach (range(1, 500) as $_) {
            $cp = (string) random_int(1000000, 9999999);
            $ir = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $serial = random_int(0, Sgtin96Codec::SERIAL_MAX);

            $decoded = $this->codec->decode($this->codec->encode($cp, $ir, $serial));

            $this->assertSame($cp, $decoded['companyPrefix']);
            $this->assertSame($ir, $decoded['itemReference']);
            $this->assertSame($serial, $decoded['serial']);
        }
    }

    public function test_dos_seriales_distintos_nunca_dan_el_mismo_epc(): void
    {
        $seen = [];

        foreach (range(1, 2000) as $serial) {
            $epc = $this->codec->encode('7751234', '012345', $serial);
            $this->assertArrayNotHasKey($epc, $seen, "EPC repetido en el serial {$serial}.");
            $seen[$epc] = true;
        }

        $this->assertCount(2000, $seen);
    }
}
