<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\MovementType;
use App\Enums\RoleCode;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Umbrales de doble aprobación de `docs/12` §2.
 *
 * `tecnico` no aparece por ninguna parte a propósito: puede diagnosticar un
 * lector pero no justificar la desaparición de mercadería.
 */
final class StockAdjustmentPolicy
{
    /** Por encima de estas unidades hace falta un supervisor regional. */
    public const UNITS_REQUIRING_APPROVAL = 20;

    /** Por encima de este valor en soles hace falta un supervisor regional. */
    public const SHRINKAGE_VALUE_REQUIRING_APPROVAL = 2000.0;

    public function apply(User $user, MovementType $type, int $units = 1, float $value = 0.0): Response
    {
        if ($type->requiresApproval() && ! $user->hasPermission('adjustment.approve')) {
            return Response::deny('No tienes permiso para ajustar stock.');
        }

        if ($units > self::UNITS_REQUIRING_APPROVAL && ! $this->isRegional($user)) {
            return Response::deny(sprintf(
                'Un ajuste de %d unidades supera el límite de %d y necesita a un supervisor regional.',
                $units,
                self::UNITS_REQUIRING_APPROVAL,
            ));
        }

        if ($type === MovementType::Merma
            && $value > self::SHRINKAGE_VALUE_REQUIRING_APPROVAL
            && ! $this->isRegional($user)
        ) {
            return Response::deny(sprintf(
                'Una merma de S/ %s supera el límite de S/ %s y necesita a un supervisor regional.',
                number_format($value, 2),
                number_format(self::SHRINKAGE_VALUE_REQUIRING_APPROVAL, 2),
            ));
        }

        return Response::allow();
    }

    /** Anular un lote ya tarado solo lo puede hacer un administrador. */
    public function voidTagBatch(User $user): Response
    {
        return $user->hasRole(RoleCode::Admin)
            ? Response::allow()
            : Response::deny('Solo un administrador puede anular un lote de tags ya tarados.');
    }

    /**
     * Cambiar la máscara EPC invalida el filtro de todo el sistema, así que
     * exige administrador y motivo escrito para la auditoría.
     */
    public function changeEpcMask(User $user, ?string $reason): Response
    {
        if (! $user->hasRole(RoleCode::Admin)) {
            return Response::deny('Solo un administrador puede cambiar la máscara EPC.');
        }

        return blank($reason)
            ? Response::deny('Cambiar la máscara EPC exige un motivo escrito para la auditoría.')
            : Response::allow();
    }

    private function isRegional(User $user): bool
    {
        return $user->hasAnyRole([RoleCode::SupervisorRegional, RoleCode::Admin]);
    }
}
