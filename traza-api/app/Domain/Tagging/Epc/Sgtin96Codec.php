<?php

declare(strict_types=1);

namespace App\Domain\Tagging\Epc;

use InvalidArgumentException;

/**
 * Codificador y decodificador SGTIN-96 (EPCglobal Tag Data Standard).
 *
 * Ver `docs/04` §2.1 y §4. No usa GMP: `BitField` compone los 96 bits sin
 * pérdida de precisión y sin depender de ninguna extensión de PHP.
 */
final class Sgtin96Codec implements EpcCodec
{
    public const HEADER = 0x30;

    /** partición => [bits prefijo, dígitos prefijo, bits itemRef, dígitos itemRef] */
    private const PARTITIONS = [
        0 => [40, 12, 4, 1],
        1 => [37, 11, 7, 2],
        2 => [34, 10, 10, 3],
        3 => [30, 9, 14, 4],
        4 => [27, 8, 17, 5],
        5 => [24, 7, 20, 6],
        6 => [20, 6, 24, 7],
    ];

    private const SERIAL_BITS = 38;

    public const SERIAL_MAX = 274877906943; // 2^38 - 1

    public function scheme(): string
    {
        return 'sgtin-96';
    }

    public function maxSerial(): int
    {
        return self::SERIAL_MAX;
    }

    /** @return array{0:int,1:int,2:int,3:int} */
    public static function partitionSpec(int $partition): array
    {
        if (! isset(self::PARTITIONS[$partition])) {
            throw new InvalidArgumentException("Partición inválida: {$partition}.");
        }

        return self::PARTITIONS[$partition];
    }

    public function encode(string $prefix, string $reference, int $serial, int $filter = 1): string
    {
        $this->assertDigits($prefix, 'El prefijo de compañía');
        $this->assertDigits($reference, 'La referencia de artículo');

        $partition = $this->partitionForPrefixLength(strlen($prefix));
        [$cpBits, , $irBits, $irDigits] = self::PARTITIONS[$partition];

        if (strlen($reference) !== $irDigits) {
            throw new InvalidArgumentException(
                "La referencia de artículo debe tener {$irDigits} dígitos para la partición {$partition}."
            );
        }
        if ($serial < 0 || $serial > self::SERIAL_MAX) {
            throw new InvalidArgumentException('Serial fuera del rango de 38 bits.');
        }
        if ($filter < 0 || $filter > 7) {
            throw new InvalidArgumentException('Filter debe estar entre 0 y 7.');
        }

        return BitField::pack([
            [self::HEADER, 8],
            [$filter, 3],
            [$partition, 3],
            [(int) $prefix, $cpBits],
            [(int) $reference, $irBits],
            [$serial, self::SERIAL_BITS],
        ], 96);
    }

    public function matches(string $epcHex): bool
    {
        $epcHex = strtoupper(trim($epcHex));

        return preg_match('/^[0-9A-F]{24}$/', $epcHex) === 1
            && hexdec(substr($epcHex, 0, 2)) === self::HEADER;
    }

    /**
     * @return array{scheme:string,filter:int,partition:int,companyPrefix:string,
     *               itemReference:string,serial:int,gtin13:string}
     */
    public function decode(string $epcHex): array
    {
        $epcHex = strtoupper(trim($epcHex));

        if (preg_match('/^[0-9A-F]{24}$/', $epcHex) !== 1) {
            throw new InvalidArgumentException('Un EPC SGTIN-96 debe tener 24 caracteres hexadecimales.');
        }

        $bits = BitField::hexToBits($epcHex);

        $header = BitField::slice($bits, 0, 8);
        if ($header !== self::HEADER) {
            throw new InvalidArgumentException(sprintf('Header 0x%02X no es SGTIN-96.', $header));
        }

        $filter = BitField::slice($bits, 8, 3);
        $partition = BitField::slice($bits, 11, 3);

        if (! isset(self::PARTITIONS[$partition])) {
            throw new InvalidArgumentException("Partición inválida: {$partition}.");
        }
        [$cpBits, $cpDigits, $irBits, $irDigits] = self::PARTITIONS[$partition];

        $prefix = str_pad((string) BitField::slice($bits, 14, $cpBits), $cpDigits, '0', STR_PAD_LEFT);
        $reference = str_pad((string) BitField::slice($bits, 14 + $cpBits, $irBits), $irDigits, '0', STR_PAD_LEFT);
        $serial = BitField::slice($bits, 14 + $cpBits + $irBits, self::SERIAL_BITS);

        $gtin14 = $this->toGtin14($prefix, $reference);

        return [
            'scheme' => $this->scheme(),
            'filter' => $filter,
            'partition' => $partition,
            'companyPrefix' => $prefix,
            'itemReference' => $reference,
            'serial' => $serial,
            'gtin14' => $gtin14,
            'gtin13' => $this->toGtin13($prefix, $reference),
        ];
    }

    /**
     * GTIN-14: indicador (primer dígito de la referencia) + prefijo de
     * compañía + resto de la referencia + dígito de control.
     *
     * En SGTIN la suma de dígitos de prefijo y referencia es siempre 13 en
     * todas las particiones, así que con el control salen 14. Por eso la
     * columna del esquema es `VARCHAR(14)`.
     */
    public function toGtin14(string $companyPrefix, string $itemReference): string
    {
        $base = $itemReference[0].$companyPrefix.substr($itemReference, 1);

        return $base.$this->checkDigit($base);
    }

    /**
     * GTIN-13, el número que va detrás del código de barras EAN-13.
     *
     * Solo existe cuando el dígito indicador es 0, que es el caso de una
     * unidad de consumo: una prenda suelta. Con indicador distinto de 0 se
     * trata de un agrupamiento (caja, pallet) y no hay EAN-13 equivalente,
     * así que se devuelve null en vez de un número inventado.
     */
    public function toGtin13(string $companyPrefix, string $itemReference): ?string
    {
        if ($itemReference[0] !== '0') {
            return null;
        }

        // El dígito de control no cambia al quitar ceros por la izquierda.
        return substr($this->toGtin14($companyPrefix, $itemReference), 1);
    }

    /** Dígito de control GS1: módulo 10 con pesos 3 y 1 desde la derecha. */
    public function checkDigit(string $digits): string
    {
        $sum = 0;
        $position = 0;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $sum += ((int) $digits[$i]) * ($position % 2 === 0 ? 3 : 1);
            $position++;
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }

    private function partitionForPrefixLength(int $length): int
    {
        foreach (self::PARTITIONS as $partition => [, $digits]) {
            if ($digits === $length) {
                return $partition;
            }
        }

        throw new InvalidArgumentException("No hay partición para un prefijo de {$length} dígitos.");
    }

    private function assertDigits(string $value, string $label): void
    {
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new InvalidArgumentException("{$label} solo puede contener dígitos.");
        }
    }
}
