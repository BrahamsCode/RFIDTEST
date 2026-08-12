<?php

declare(strict_types=1);

namespace App\Domain\Movements;

use App\Enums\MovementType;
use App\Enums\TagState;
use Carbon\CarbonInterface;

/**
 * Objeto de valor inmutable: la intención de mover stock.
 *
 * Describe qué se quiere hacer, no cómo. Quien lo ejecuta es
 * `StockMovementService`.
 */
final readonly class MovementIntent
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public ?int $tagId = null,
        public ?int $productVariantId = null,
        public MovementType $type = MovementType::CambioZona,
        public int $quantity = 1,
        public ?int $toLocationId = null,
        public ?int $toZoneId = null,
        public ?TagState $targetState = null,
        public ?int $userId = null,
        public ?int $deviceId = null,
        public ?string $referenceType = null,
        public ?int $referenceId = null,
        public ?string $reason = null,
        public ?float $unitCost = null,
        public array $metadata = [],
        public ?CarbonInterface $occurredAt = null,
    ) {}

    /** Deriva la misma intención para otro tag. Base de `applyBulk()`. */
    public function forTag(int $tagId): self
    {
        return new self(
            tagId: $tagId,
            productVariantId: $this->productVariantId,
            type: $this->type,
            quantity: $this->quantity,
            toLocationId: $this->toLocationId,
            toZoneId: $this->toZoneId,
            targetState: $this->targetState,
            userId: $this->userId,
            deviceId: $this->deviceId,
            referenceType: $this->referenceType,
            referenceId: $this->referenceId,
            reason: $this->reason,
            unitCost: $this->unitCost,
            metadata: $this->metadata,
            occurredAt: $this->occurredAt,
        );
    }
}
