<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\InventoryCycle;
use App\Models\User;

/**
 * Quién puede escuchar qué canal. Ver `docs/06` §8.
 *
 * La lógica vive aquí y no en `routes/channels.php` para que sea probable
 * directamente: verificarla a través de `/broadcasting/auth` depende de toda
 * la fontanería de sesión y difusión, y un fallo ahí se confunde con un fallo
 * de permisos.
 *
 * Los parámetros del canal llegan como cadena, extraídos del nombre del
 * canal; por eso las firmas los aceptan así y castean.
 */
final class ChannelAccess
{
    public static function inventoryCycle(User $user, string|int $cycleId): bool
    {
        $cycle = InventoryCycle::find((int) $cycleId);

        return $cycle !== null && $user->canAccessLocation($cycle->location_id);
    }

    /** Alarmas de portal: información de seguridad de una tienda concreta. */
    public static function locationAlerts(User $user, string|int $locationId): bool
    {
        return $user->canAccessLocation((int) $locationId);
    }

    public static function locationStock(User $user, string|int $locationId): bool
    {
        return $user->canAccessLocation((int) $locationId);
    }

    /** El panel de operaciones es de la organización, no de una tienda. */
    public static function organizationDevices(User $user, string|int $organizationId): bool
    {
        return $user->organization_id === (int) $organizationId
            && $user->hasPermission('device.manage');
    }
}
