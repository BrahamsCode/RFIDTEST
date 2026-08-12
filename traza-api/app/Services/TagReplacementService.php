<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Movements\MovementIntent;
use App\Enums\MovementType;
use App\Enums\TagState;
use App\Models\Tag;
use App\Models\TagReplacement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sustitución de tag por hangtag arrancado o ilegible. Ver P11 de `docs/10`.
 */
final class TagReplacementService
{
    public function __construct(
        private readonly StockMovementService $movements,
    ) {}

    /**
     * Registra que la prenda que llevaba `$oldTag` ahora lleva `$newTag`.
     *
     * Lo importante es dar de baja el tag viejo: si no, la prenda contaría
     * dos veces en el ciclo siguiente, una por cada EPC.
     */
    public function replace(
        Tag $oldTag,
        Tag $newTag,
        string $reason,
        ?int $userId = null,
    ): TagReplacement {
        if ($oldTag->id === $newTag->id) {
            throw new RuntimeException('Una prenda no puede sustituirse a sí misma.');
        }

        if ($oldTag->organization_id !== $newTag->organization_id) {
            throw new RuntimeException('Los dos tags deben pertenecer a la misma organización.');
        }

        if (TagReplacement::where('new_tag_id', $newTag->id)->exists()) {
            throw new RuntimeException(
                "El EPC {$newTag->epc} ya se usó como sustituto de otra prenda."
            );
        }

        return DB::transaction(function () use ($oldTag, $newTag, $reason, $userId): TagReplacement {
            $location = $oldTag->current_location_id;
            $zone = $oldTag->current_zone_id;

            // El tag viejo sale del inventario. Sin esto la prenda se contaría
            // dos veces en el próximo ciclo.
            if (! $oldTag->state->isTerminal()) {
                $this->movements->apply(new MovementIntent(
                    tagId: $oldTag->id,
                    type: MovementType::Reetiquetado,
                    userId: $userId,
                    referenceType: 'tag_replacement',
                    reason: "Sustituido por {$newTag->epc}: {$reason}",
                ));
            }

            // El nuevo hereda la ubicación y la zona de la prenda física.
            if ($newTag->state !== TagState::EnStock) {
                $this->movements->apply(new MovementIntent(
                    tagId: $newTag->id,
                    type: MovementType::Tarado,
                    toLocationId: $location,
                    toZoneId: $zone,
                    userId: $userId,
                    referenceType: 'tag_replacement',
                    reason: "Sustituye a {$oldTag->epc}: {$reason}",
                ));
            }

            $newTag->forceFill(['replaces_tag_id' => $oldTag->id])->save();

            return TagReplacement::create([
                'old_tag_id' => $oldTag->id,
                'new_tag_id' => $newTag->id,
                'reason' => $reason,
                'performed_by' => $userId,
                'performed_at' => now(),
            ]);
        });
    }
}
