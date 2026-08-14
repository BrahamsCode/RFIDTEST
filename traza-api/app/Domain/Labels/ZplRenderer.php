<?php

declare(strict_types=1);

namespace App\Domain\Labels;

/**
 * Genera el ZPL de una etiqueta colgante RFID. Ver `docs/04` §5.
 *
 * La impresora codifica y escribe el EPC **durante la impresión**: no hay un
 * paso posterior de tarado por radio. Por eso el ZPL lleva tanto el diseño
 * visible como los comandos `^RS`/`^RFW`, y por eso importa que el EPC del
 * `^RFW` y el texto impreso describan la misma prenda.
 */
final class ZplRenderer
{
    /** Reintentos de escritura antes de dar el inlay por muerto. */
    private const RETRIES = 3;

    /** Bytes del banco EPC: 96 bits. */
    private const EPC_BYTES = 12;

    /**
     * Etiqueta de una prenda.
     *
     * @param  array{name: string, sku: string, size?: ?string, color?: ?string,
     *               price?: ?float, currency?: string, barcode?: ?string}  $item
     */
    public function label(string $epc, array $item): string
    {
        $epc = strtoupper($epc);

        $this->assertValidEpc($epc);

        $lines = [
            '^XA',
            // Gen2, 3 reintentos. Al fallar, la impresora marca VOID y sigue
            // con la siguiente etiqueta del rollo.
            sprintf('^RS8,,,%d,N', self::RETRIES),
            sprintf('^RFW,H,1,%d,1', self::EPC_BYTES),
            '^FD'.$epc.'^FS',
            // Verificación tras escribir: sin esto un inlay muerto sale del
            // rollo con la prenda ya colgada y el error se descubre en el
            // primer inventario.
            '^WV,Y',
            '',
            '^FO30,30^A0N,28,28^FD'.$this->escape($this->upper($item['name'])).'^FS',
        ];

        $variant = trim(sprintf(
            'Talla: %s   Color: %s',
            $item['size'] ?? '-',
            $item['color'] ?? '-',
        ));
        $lines[] = '^FO30,70^A0N,24,24^FD'.$this->escape($variant).'^FS';

        if (isset($item['price']) && $item['price'] !== null) {
            $lines[] = sprintf(
                '^FO30,110^A0N,40,40^FD%s %s^FS',
                $item['currency'] ?? 'S/',
                number_format((float) $item['price'], 2, '.', ''),
            );
        }

        if (! empty($item['barcode'])) {
            // EAN-13 impreso: la caja tiene que poder cobrar aunque el lector
            // RFID esté caído o la prenda venga de una tienda sin tarar.
            $lines[] = '^FO30,170^BY2^BCN,80,Y,N,N^FD'.$this->escape((string) $item['barcode']).'^FS';
        }

        $lines[] = '^FO420,30^A0N,20,20^FDSKU '.$this->escape($item['sku']).'^FS';
        $lines[] = '^XZ';

        return implode("\n", $lines)."\n";
    }

    /**
     * Un lote entero, listo para mandar al puerto 9100 de la impresora.
     *
     * @param  list<array{epc: string, item: array<string, mixed>}>  $labels
     */
    public function batch(array $labels): string
    {
        return implode('', array_map(
            fn (array $l) => $this->label($l['epc'], $l['item']),
            $labels,
        ));
    }

    /**
     * Comprobación de resultado de `docs/04` §5. Se manda tras el lote para
     * saber cuántos inlays fallaron: por encima del 1 % hay que reclamar al
     * proveedor.
     */
    public function queryResult(): string
    {
        return "^XA\n^RQ\n^XZ\n";
    }

    private function assertValidEpc(string $epc): void
    {
        if (preg_match('/^[0-9A-F]{24}$/', $epc) !== 1) {
            throw new \InvalidArgumentException(
                "El EPC «{$epc}» no es hexadecimal de 96 bits; la impresora lo rechazaría "
                .'o escribiría basura en el tag.'
            );
        }
    }

    /**
     * `^` y `~` son los prefijos de comando de ZPL. Un nombre de producto que
     * los lleve —«CAMISA ~ OFERTA»— partiría la etiqueta en dos comandos y la
     * impresora haría cualquier cosa.
     */
    private function escape(string $text): string
    {
        return str_replace(['^', '~'], ['', '-'], $text);
    }

    private function upper(string $text): string
    {
        return mb_strtoupper($text, 'UTF-8');
    }
}
