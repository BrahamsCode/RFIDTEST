<?php

declare(strict_types=1);

namespace App\Domain\Tagging\Epc;

use InvalidArgumentException;

/**
 * Elige el codificador. El sistema soporta los dos esquemas a la vez
 * (ADR-009): migrar de GID-96 a SGTIN-96 no puede obligar a re-etiquetar el
 * inventario ya existente, así que hay que poder decodificar ambos.
 */
final class EpcCodecFactory
{
    /** @var array<string, EpcCodec> */
    private array $codecs;

    public function __construct()
    {
        $this->codecs = [
            'sgtin-96' => new Sgtin96Codec(),
            'gid-96' => new Gid96Codec(),
        ];
    }

    public function for(string $scheme): EpcCodec
    {
        return $this->codecs[strtolower($scheme)]
            ?? throw new InvalidArgumentException("Esquema EPC no soportado: {$scheme}.");
    }

    /** El esquema configurado para emitir etiquetas nuevas. */
    public function default(): EpcCodec
    {
        return $this->for((string) config('traza.epc.scheme', 'sgtin-96'));
    }

    /**
     * Deduce el esquema por la cabecera del propio EPC. Es lo que permite
     * leer un tag antiguo sin saber de antemano cómo se codificó.
     */
    public function detect(string $epcHex): EpcCodec
    {
        foreach ($this->codecs as $codec) {
            if ($codec->matches($epcHex)) {
                return $codec;
            }
        }

        throw new InvalidArgumentException(
            'No se reconoce el esquema del EPC '.strtoupper(substr($epcHex, 0, 4)).'…'
        );
    }

    /** @return array<string, mixed> */
    public function decode(string $epcHex): array
    {
        return $this->detect($epcHex)->decode($epcHex);
    }
}
