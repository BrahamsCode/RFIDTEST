<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Organization;
use Illuminate\Support\Facades\Cache;

/**
 * Filtro [1] del pipeline, replicado en el servidor.
 *
 * El borde ya descarta los EPC ajenos, pero se vuelve a comprobar aquí: el
 * servidor nunca confía en la validación del cliente. Un borde comprometido o
 * mal configurado no debe poder meter el inventario del vecino.
 */
final readonly class EpcMask
{
    /** @param list<string> $prefixes */
    private function __construct(
        private array $prefixes,
        private string $testPrefix,
        private bool $isProduction,
    ) {}

    public static function forOrganization(int $organizationId): self
    {
        $mask = Cache::remember(
            "epc_mask:{$organizationId}",
            now()->addMinutes(10),
            fn () => Organization::query()->whereKey($organizationId)->value('epc_filter_mask')
        );

        return self::fromMask($mask);
    }

    public static function fromMask(?string $mask): self
    {
        $configured = $mask ?: config('traza.epc.mask');

        $prefixes = array_values(array_filter(array_map(
            static fn (string $p): string => strtoupper(trim($p)),
            explode(',', (string) $configured)
        )));

        return new self(
            $prefixes,
            strtoupper((string) config('traza.epc.test_prefix')),
            app()->environment('production'),
        );
    }

    public function matches(string $epc): bool
    {
        $epc = strtoupper($epc);

        // Un EPC no hexadecimal no es un EPC: viene de un lector mal
        // configurado o de un cliente que no es el nuestro.
        if (! preg_match('/^[0-9A-F]+$/', $epc)) {
            return false;
        }

        // En producción un tag de laboratorio en tienda es un error grave.
        if ($this->isProduction && $this->testPrefix !== '' && str_starts_with($epc, $this->testPrefix)) {
            return false;
        }

        if ($this->prefixes === []) {
            return true;
        }

        foreach ($this->prefixes as $prefix) {
            if (str_starts_with($epc, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function prefixes(): array
    {
        return $this->prefixes;
    }
}
