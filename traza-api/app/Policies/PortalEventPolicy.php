<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PortalEvent;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class PortalEventPolicy
{
    public function view(User $user, PortalEvent $event): Response
    {
        return $user->canAccessLocation($event->location_id)
            ? Response::allow()
            : Response::deny('No tienes acceso a esta tienda.');
    }

    /**
     * Marcar un falso positivo lo puede hacer cualquiera que atienda la
     * puerta, y es deliberado: si hiciera falta llamar al jefe de tienda,
     * nadie lo registraría y el indicador de `docs/13` §2 quedaría vacío,
     * que es justo lo que impide calibrar el portal.
     *
     * El riesgo evidente —un empleado descartando su propio hurto— no se
     * cubre con permisos sino con datos: queda en auditoría con nombre y
     * hora, y una tasa anómala por usuario salta en el informe.
     */
    public function markFalsePositive(User $user, PortalEvent $event): Response
    {
        if (! $user->hasPermission('alert.view')) {
            return Response::deny('No tienes permiso sobre las alertas.');
        }

        if (! $user->canAccessLocation($event->location_id)) {
            return Response::deny('No tienes acceso a esta tienda.');
        }

        if (! $event->alarm_raised) {
            return Response::deny('Este tránsito no generó alarma.');
        }

        return Response::allow();
    }
}
