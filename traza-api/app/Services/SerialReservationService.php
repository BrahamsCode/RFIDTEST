<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Tagging\Epc\EpcCodecFactory;
use App\Domain\Tagging\SerialRange;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Envoltorio de la función SQL `reserve_serial_range()`. Ver `docs/04` §3.2.
 *
 * La atomicidad la garantiza la base: el `UPDATE ... RETURNING` sobre la fila
 * del contador toma un bloqueo de fila y serializa a los productores. Aquí no
 * hay que añadir ningún bloqueo, y hacerlo solo empeoraría la contención.
 */
final class SerialReservationService
{
    public function __construct(
        private readonly EpcCodecFactory $codecs,
    ) {}

    public function reserve(ProductVariant $variant, int $count): SerialRange
    {
        if ($count <= 0) {
            throw new RuntimeException('El número de seriales a reservar debe ser positivo.');
        }

        $row = DB::selectOne(
            'SELECT * FROM reserve_serial_range(?, ?)',
            [$variant->id, $count],
        );

        $range = new SerialRange((int) $row->serial_from, (int) $row->serial_to);

        $this->assertWithinSchemeCapacity($range);

        return $range;
    }

    /**
     * Reserva y codifica de una vez: devuelve los EPC listos para imprimir.
     *
     * @return list<string>
     */
    public function reserveEpcs(ProductVariant $variant, int $count, ?string $scheme = null): array
    {
        $codec = $scheme === null ? $this->codecs->default() : $this->codecs->for($scheme);
        $range = $this->reserve($variant, $count);

        [$prefix, $reference] = $this->identityFor($variant, $codec->scheme());

        $epcs = [];
        foreach ($range as $serial) {
            $epcs[] = $codec->encode($prefix, $reference, $serial);
        }

        return $epcs;
    }

    /** Cuántos seriales quedan libres para esta variante. */
    public function remaining(ProductVariant $variant): int
    {
        $last = (int) DB::table('product_variant_counters')
            ->where('product_variant_id', $variant->id)
            ->value('last_serial');

        return $this->codecs->default()->maxSerial() - $last;
    }

    /**
     * Prefijo y referencia con los que se codifica esta variante.
     *
     * @return array{0:string,1:string}
     */
    private function identityFor(ProductVariant $variant, string $scheme): array
    {
        if ($scheme === 'gid-96') {
            // Sin GS1 el general manager es un valor propio y el object
            // class es el identificador interno de la variante.
            return [
                (string) (int) config('traza.epc.gid_manager_number', 1),
                (string) $variant->id,
            ];
        }

        $prefix = (string) config('traza.epc.gs1_company_prefix');

        if ($prefix === '') {
            throw new RuntimeException(
                'No hay prefijo de compañía GS1 configurado. Defina TRAZA_GS1_COMPANY_PREFIX '
                .'o cambie TRAZA_EPC_SCHEME a gid-96 (ver tarea 0.2).'
            );
        }

        $reference = $variant->item_reference;

        if ($reference === null || $reference === '') {
            throw new RuntimeException(
                "La variante {$variant->sku} no tiene item_reference; no se puede codificar su EPC."
            );
        }

        return [$prefix, $reference];
    }

    private function assertWithinSchemeCapacity(SerialRange $range): void
    {
        $max = $this->codecs->default()->maxSerial();

        if ($range->to > $max) {
            throw new RuntimeException(sprintf(
                'El rango reservado llega a %d y el esquema solo admite hasta %d. '
                .'Esta variante agotó su espacio de seriales.',
                $range->to,
                $max,
            ));
        }
    }
}
