<?php

declare(strict_types=1);

namespace App\Domain\Tagging\Epc;

use InvalidArgumentException;

/**
 * Codificador GID-96 (General Identifier). Ver `docs/04` §2.2.
 *
 * No requiere GS1, pero **no es interoperable**: ningún socio comercial podrá
 * interpretar estos EPC. Es la vía de arranque mientras no haya prefijo de
 * compañía, con migración planificada a SGTIN-96 (ADR-009).
 */
final class Gid96Codec implements EpcCodec
{
    public const HEADER = 0x35;

    private const MANAGER_BITS = 28;

    private const CLASS_BITS = 24;

    private const SERIAL_BITS = 36;

    public const MANAGER_MAX = 268435455;    // 2^28 - 1

    public const CLASS_MAX = 16777215;       // 2^24 - 1

    public const SERIAL_MAX = 68719476735;   // 2^36 - 1

    public function scheme(): string
    {
        return 'gid-96';
    }

    public function maxSerial(): int
    {
        return self::SERIAL_MAX;
    }

    /**
     * GID-96 no tiene campo filter; el parámetro existe por compatibilidad
     * con la interfaz y se ignora.
     */
    public function encode(string $prefix, string $reference, int $serial, int $filter = 1): string
    {
        $manager = $this->toInt($prefix, 'El general manager number');
        $objectClass = $this->toInt($reference, 'El object class');

        if ($manager > self::MANAGER_MAX) {
            throw new InvalidArgumentException('General manager number fuera del rango de 28 bits.');
        }
        if ($objectClass > self::CLASS_MAX) {
            throw new InvalidArgumentException('Object class fuera del rango de 24 bits.');
        }
        if ($serial < 0 || $serial > self::SERIAL_MAX) {
            throw new InvalidArgumentException('Serial fuera del rango de 36 bits.');
        }

        return BitField::pack([
            [self::HEADER, 8],
            [$manager, self::MANAGER_BITS],
            [$objectClass, self::CLASS_BITS],
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
     * @return array{scheme:string,managerNumber:int,objectClass:int,serial:int}
     */
    public function decode(string $epcHex): array
    {
        $epcHex = strtoupper(trim($epcHex));

        if (preg_match('/^[0-9A-F]{24}$/', $epcHex) !== 1) {
            throw new InvalidArgumentException('Un EPC GID-96 debe tener 24 caracteres hexadecimales.');
        }

        $bits = BitField::hexToBits($epcHex);

        $header = BitField::slice($bits, 0, 8);
        if ($header !== self::HEADER) {
            throw new InvalidArgumentException(sprintf('Header 0x%02X no es GID-96.', $header));
        }

        return [
            'scheme' => $this->scheme(),
            'managerNumber' => BitField::slice($bits, 8, self::MANAGER_BITS),
            'objectClass' => BitField::slice($bits, 36, self::CLASS_BITS),
            'serial' => BitField::slice($bits, 60, self::SERIAL_BITS),
        ];
    }

    private function toInt(string $value, string $label): int
    {
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new InvalidArgumentException("{$label} solo puede contener dígitos.");
        }

        return (int) $value;
    }
}
