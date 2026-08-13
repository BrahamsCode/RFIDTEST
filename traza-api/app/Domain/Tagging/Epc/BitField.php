<?php

declare(strict_types=1);

namespace App\Domain\Tagging\Epc;

use InvalidArgumentException;

/**
 * Compone y descompone valores de 96 bits como cadena de bits.
 *
 * PHP no tiene enteros de 96 bits, y usar `int` provocaría corrupción
 * silenciosa de EPC, que es el peor fallo posible de este sistema. La
 * documentación resuelve esto con GMP; aquí se hace con una cadena de bits,
 * que es igual de exacta y **no depende de ninguna extensión**: un requisito
 * menos que puede faltar en el mini-PC de una tienda.
 *
 * Cada campo individual cabe holgadamente en un entero de 64 bits (el mayor
 * es el prefijo de 40 bits), así que solo la concatenación necesita cuidado.
 */
final class BitField
{
    /** @param list<array{int, int}> $fields pares [valor, número de bits] */
    public static function pack(array $fields, int $totalBits): string
    {
        $bits = '';

        foreach ($fields as [$value, $width]) {
            $bits .= self::toBits($value, $width);
        }

        if (strlen($bits) !== $totalBits) {
            throw new InvalidArgumentException(sprintf(
                'La suma de los campos da %d bits, se esperaban %d.',
                strlen($bits),
                $totalBits,
            ));
        }

        return self::bitsToHex($bits);
    }

    /** Extrae `$width` bits empezando en `$offset` desde la izquierda. */
    public static function slice(string $bits, int $offset, int $width): int
    {
        if ($width > 62) {
            throw new InvalidArgumentException('Un campo de más de 62 bits no cabe en un int con seguridad.');
        }

        return (int) base_convert(substr($bits, $offset, $width), 2, 10);
    }

    public static function hexToBits(string $hex): string
    {
        $bits = '';

        foreach (str_split(strtoupper($hex)) as $nibble) {
            $value = (int) hexdec($nibble);
            $bits .= str_pad(decbin($value), 4, '0', STR_PAD_LEFT);
        }

        return $bits;
    }

    private static function toBits(int $value, int $width): string
    {
        if ($value < 0) {
            throw new InvalidArgumentException('Los campos de un EPC no admiten valores negativos.');
        }

        // 2**$width con $width hasta 62 no desborda; por encima se rechaza.
        if ($width <= 62 && $value >= (1 << $width)) {
            throw new InvalidArgumentException(
                "El valor {$value} no cabe en {$width} bits."
            );
        }

        $out = '';
        for ($i = $width - 1; $i >= 0; $i--) {
            $out .= (($value >> $i) & 1) ? '1' : '0';
        }

        return $out;
    }

    private static function bitsToHex(string $bits): string
    {
        $hex = '';

        foreach (str_split($bits, 4) as $nibble) {
            $hex .= strtoupper(dechex((int) bindec($nibble)));
        }

        return $hex;
    }
}
