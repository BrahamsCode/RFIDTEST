<?php

declare(strict_types=1);

namespace App\Domain\Tagging\Epc;

/**
 * Estrategia de codificación de EPC.
 *
 * El sistema soporta varios esquemas a la vez desde el diseño (ADR-009):
 * arrancar con GID-96 y migrar a SGTIN-96 no debe obligar a re-etiquetar el
 * inventario existente, así que `tags.epc_scheme` guarda con qué esquema se
 * codificó cada prenda y el codificador se elige por ahí.
 */
interface EpcCodec
{
    /** Identificador del esquema, tal como se guarda en `tags.epc_scheme`. */
    public function scheme(): string;

    /**
     * @param  string  $prefix  prefijo de compañía (SGTIN) o general manager (GID)
     * @param  string  $reference  referencia de artículo (SGTIN) u object class (GID)
     */
    public function encode(string $prefix, string $reference, int $serial, int $filter = 1): string;

    /** @return array<string, mixed> */
    public function decode(string $epcHex): array;

    /** Serial máximo que admite el esquema. */
    public function maxSerial(): int;

    /** ¿Este EPC parece de este esquema? Se decide por la cabecera. */
    public function matches(string $epcHex): bool;
}
