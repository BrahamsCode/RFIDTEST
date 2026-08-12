<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

final readonly class ReconciliationResult
{
    public function __construct(
        /** Esperados y encontrados. */
        public int $found,
        /** Esperados y NO encontrados. */
        public int $missing,
        /** Encontrados y NO esperados. */
        public int $unexpected,
        /** Cuántos faltantes cruzaron el umbral y pasaron a perdidos. */
        public int $declaredLost = 0,
    ) {}

    public function counted(): int
    {
        return $this->found + $this->unexpected;
    }

    public function accuracyPct(int $expected): ?float
    {
        return $expected > 0 ? round(100 * $this->found / $expected, 3) : null;
    }
}
