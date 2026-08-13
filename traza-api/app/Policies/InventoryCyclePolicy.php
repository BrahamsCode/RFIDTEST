<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\InventoryCycle;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\DB;

final class InventoryCyclePolicy
{
    public function view(User $user, InventoryCycle $cycle): Response
    {
        return $user->canAccessLocation($cycle->location_id)
            ? Response::allow()
            : Response::deny('No tienes acceso a esta tienda.');
    }

    public function create(User $user, ?int $locationId = null): Response
    {
        if (! $user->hasPermission('cycle.create')) {
            return Response::deny('No tienes permiso para crear ciclos de inventario.');
        }

        if ($locationId !== null && ! $user->canAccessLocation($locationId)) {
            return Response::deny('No tienes acceso a esta tienda.');
        }

        return Response::allow();
    }

    public function close(User $user, InventoryCycle $cycle): Response
    {
        if (! $user->canAccessLocation($cycle->location_id)) {
            return Response::deny('No tienes acceso a esta tienda.');
        }

        if (! $user->hasAnyRole([RoleCode::JefeTienda, RoleCode::SupervisorRegional, RoleCode::Admin])) {
            return Response::deny('Solo el jefe de tienda puede cerrar un ciclo.');
        }

        /*
         * Un ciclo con exactitud muy baja casi siempre significa que faltó
         * barrer una zona. Cerrarlo genera merma falsa y destruye la
         * confianza en el sistema, así que se exige justificación explícita.
         */
        $accuracy = $this->provisionalAccuracy($cycle);

        if ($accuracy !== null && $accuracy < 90.0 && blank($cycle->notes)) {
            return Response::deny(sprintf(
                'La exactitud provisional es %.1f %%. Revisa el desglose por zona y vuelve a '
                .'barrer las zonas bajas, o escribe una justificación antes de cerrar.',
                $accuracy,
            ));
        }

        return Response::allow();
    }

    /**
     * Exactitud estimada antes de conciliar: cuántos de los esperados se han
     * detectado hasta ahora.
     */
    public function provisionalAccuracy(InventoryCycle $cycle): ?float
    {
        $expected = (int) ($cycle->expected_count ?? 0);

        if ($expected === 0) {
            return null;
        }

        $found = DB::table('inventory_cycle_expected as e')
            ->join('inventory_cycle_scans as s', function ($join) use ($cycle): void {
                $join->on('s.tag_id', '=', 'e.tag_id')
                    ->where('s.inventory_cycle_id', '=', $cycle->id);
            })
            ->where('e.inventory_cycle_id', $cycle->id)
            ->count();

        return round(100 * $found / $expected, 3);
    }
}
