<?php

declare(strict_types=1);

use App\Broadcasting\ChannelAccess;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
| Cableado de los canales privados. La lógica está en `ChannelAccess`, que se
| prueba directamente.
|
| Sin autorización, cualquier usuario autenticado podría escuchar las alarmas
| de portal de cualquier tienda.
*/

Broadcast::channel(
    'inventory-cycle.{cycleId}',
    fn (User $user, string $cycleId): bool => ChannelAccess::inventoryCycle($user, $cycleId),
);

Broadcast::channel(
    'location.{locationId}.alerts',
    fn (User $user, string $locationId): bool => ChannelAccess::locationAlerts($user, $locationId),
);

Broadcast::channel(
    'location.{locationId}.stock',
    fn (User $user, string $locationId): bool => ChannelAccess::locationStock($user, $locationId),
);

Broadcast::channel(
    'org.{organizationId}.devices',
    fn (User $user, string $organizationId): bool => ChannelAccess::organizationDevices($user, $organizationId),
);
