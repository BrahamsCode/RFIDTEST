<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\AuthenticateDevice;
use App\Models\Device;
use App\Models\InventoryCycle;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\Tag;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Policies\InventoryCyclePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Modelos cuyos cambios quedan auditados. Ver `docs/12` §2.
     *
     * `stock_movements` no está porque es append-only: ya es su propia
     * auditoría, y el trigger de la base impide reescribirlo.
     *
     * @var list<class-string>
     */
    private const AUDITED = [
        Tag::class,
        InventoryCycle::class,
        Device::class,
        User::class,
        Organization::class,
        ProductVariant::class,
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        foreach (self::AUDITED as $model) {
            $model::observe(AuditObserver::class);
        }

        Gate::policy(InventoryCycle::class, InventoryCyclePolicy::class);

        $this->configureRateLimiting();
    }

    /**
     * Límites de `docs/06` §6: 300 req/min por usuario y 2000 req/min por
     * dispositivo de borde.
     *
     * El borde necesita mucho más margen porque vacía su buffer en ráfagas:
     * tras un corte de red puede tener miles de lecturas acumuladas y
     * limitarlo como a una persona haría que nunca se pusiera al día.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function ($request) {
            $device = $request->attributes->get(AuthenticateDevice::ATTRIBUTE);

            if ($device instanceof Device) {
                return Limit::perMinute(2000)->by('device:'.$device->id);
            }

            return $request->user()
                ? Limit::perMinute(300)->by('user:'.$request->user()->id)
                : Limit::perMinute(60)->by('ip:'.$request->ip());
        });
    }
}
