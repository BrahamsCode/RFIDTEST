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
     * Puntos por pulgada de la impresora. 203 es lo estándar en las Zebra de
     * sobremesa —las 8 puntos/mm— y es lo que asume el diseño de `docs/04` §5.
     */
    private const DPI = 203;

    /**
     * Extremo derecho e inferior que ocupa el diseño, en puntos.
     *
     * El campo más a la derecha es el SKU, en `^FO420,30`, y ocupa lo suyo; el
     * más abajo es el código de barras, en `^FO30,170` con 80 puntos de alto.
     * Se dejan márgenes para el texto de cada uno.
     */
    private const CONTENIDO_ANCHO = 560;

    private const CONTENIDO_ALTO = 280;

    /**
     * Caracteres que caben en el nombre antes de tocar el SKU.
     *
     * Hay 370 puntos entre el margen del nombre (30) y donde empieza el SKU
     * (420). La fuente escalable `^A0` a 28 puntos de alto ronda los 15 de
     * ancho por carácter, así que entran unos 24; se dejan 22 de margen y se
     * comprueba renderizando, que es la única forma de saberlo de verdad.
     */
    private const NOMBRE_MAX = 22;

    /**
     * @param  float  $widthInches  Ancho físico de la etiqueta.
     * @param  float  $heightInches  Alto físico de la etiqueta.
     */
    public function __construct(
        private readonly float $widthInches = 3.0,
        private readonly float $heightInches = 2.0,
    ) {}

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
        $this->assertContentFits();

        $lines = [
            '^XA',
            /*
             * Tamaño de la etiqueta, explícito en el propio ZPL.
             *
             * Sin `^PW`/`^LL` la impresora usa lo que tenga configurado, y ZPL
             * **recorta en silencio** lo que no cabe: ni error, ni aviso, ni
             * nada. Comprobado renderizando este mismo ZPL a 2×1 pulgadas, que
             * es un colgante de ropa normal: el SKU desaparece entero y el
             * código de barras sale cortado e ilegible. La prenda se cuelga
             * con una etiqueta que en caja no escanea, y eso no se descubre
             * hasta que hay una cola.
             */
            sprintf('^PW%d', $this->widthDots()),
            sprintf('^LL%d', $this->heightDots()),
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
            /*
             * El nombre se recorta antes de emitirlo.
             *
             * Sin recortar, uno largo —«Casaca Impermeable Con Capucha
             * Desmontable», que en confección es lo normal— sigue escribiendo
             * hacia la derecha, **se superpone al SKU** y deja los dos
             * ilegibles. Comprobado renderizando: ZPL no avisa, lo pinta
             * encima y ya.
             *
             * Y se recorta aquí y no con `^FB`: un bloque de una sola línea
             * **no trunca**, amontona todas las líneas que saldrían una sobre
             * otra, que es todavía peor. También comprobado renderizando.
             *
             * Perder el final del nombre es asumible —está en el sistema—;
             * perder el SKU no, porque es lo que mira el personal cuando el
             * código de barras no escanea.
             */
            '^FO30,30^A0N,28,28^FD'
                .$this->escape($this->fit($this->upper($item['name']))).'^FS',
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

    public function widthDots(): int
    {
        return (int) round($this->widthInches * self::DPI);
    }

    public function heightDots(): int
    {
        return (int) round($this->heightInches * self::DPI);
    }

    /**
     * Se niega a generar una etiqueta que no cabe.
     *
     * Es lo contrario de lo que hace ZPL, y a propósito. Una etiqueta
     * recortada es peor que ninguna: sale del rollo con buen aspecto, se
     * cuelga de la prenda, y el fallo aparece semanas después en la caja de
     * una tienda con cola. Aquí revienta el lote entero antes de gastar el
     * primer inlay.
     */
    private function assertContentFits(): void
    {
        $ancho = $this->widthDots();
        $alto = $this->heightDots();

        if ($ancho >= self::CONTENIDO_ANCHO && $alto >= self::CONTENIDO_ALTO) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'El diseño de `docs/04` §5 necesita al menos %d×%d puntos (%.1f×%.1f pulgadas '
            .'a %d ppp) y la etiqueta configurada es de %d×%d (%.1f×%.1f). ZPL recortaría '
            .'lo que sobra sin avisar: el SKU y el código de barras son lo primero que se '
            .'pierde, y eso no se nota hasta que una prenda no escanea en caja.',
            self::CONTENIDO_ANCHO,
            self::CONTENIDO_ALTO,
            self::CONTENIDO_ANCHO / self::DPI,
            self::CONTENIDO_ALTO / self::DPI,
            self::DPI,
            $ancho,
            $alto,
            $this->widthInches,
            $this->heightInches,
        ));
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

    /** Recorta el nombre a lo que cabe sin invadir el SKU. */
    private function fit(string $text): string
    {
        return mb_strlen($text, 'UTF-8') <= self::NOMBRE_MAX
            ? $text
            : rtrim(mb_substr($text, 0, self::NOMBRE_MAX - 1, 'UTF-8')).'.';
    }
}
