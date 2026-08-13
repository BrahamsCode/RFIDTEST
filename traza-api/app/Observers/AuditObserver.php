<?php

declare(strict_types=1);

namespace App\Observers;

use App\Http\Middleware\AuthenticateDevice;
use App\Models\Device;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * Deja rastro de quién tocó qué en los modelos sensibles. Tarea 9.2.
 *
 * Registra usuario, dispositivo, IP y agente. Sin esto, un ajuste manual que
 * hace desaparecer mercadería es indistinguible de un fallo del sistema, y
 * eso es justo lo que hay que poder distinguir.
 */
final class AuditObserver
{
    /** Campos que nunca deben acabar en el registro de auditoría. */
    private const REDACTED = ['password', 'remember_token', 'api_token_hash'];

    public function created(Model $model): void
    {
        $this->record($model, 'created', ['after' => $this->sanitize($model->getAttributes())]);
    }

    public function updated(Model $model): void
    {
        $changes = $this->sanitize($model->getChanges());

        // `updated_at` cambia en cada guardado; solo por eso no vale la pena
        // una fila de auditoría.
        unset($changes['updated_at']);

        if ($changes === []) {
            return;
        }

        $before = [];
        foreach (array_keys($changes) as $key) {
            $before[$key] = $model->getOriginal($key);
        }

        $this->record($model, 'updated', [
            'before' => $this->sanitize($before),
            'after' => $changes,
        ]);
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'deleted', ['before' => $this->sanitize($model->getAttributes())]);
    }

    /** @param array<string, mixed> $changes */
    private function record(Model $model, string $action, array $changes): void
    {
        $device = $this->currentDevice();

        DB::table('audit_logs')->insert([
            'organization_id' => $model->getAttribute('organization_id')
                ?? Auth::user()?->organization_id
                ?? $device?->organization_id,
            'user_id' => Auth::id(),
            'device_id' => $device?->id,
            'action' => class_basename($model).'.'.$action,
            'subject_type' => $model::class,
            'subject_id' => $model->getKey(),
            'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE),
            'ip_address' => $this->clientIp(),
            'user_agent' => Request::userAgent(),
            'created_at' => now(),
        ]);
    }

    private function currentDevice(): ?Device
    {
        if (! app()->bound('request')) {
            return null;
        }

        $device = request()->attributes->get(AuthenticateDevice::ATTRIBUTE);

        return $device instanceof Device ? $device : null;
    }

    private function clientIp(): ?string
    {
        $ip = app()->bound('request') ? request()->ip() : null;

        // La columna es INET: una cadena que no lo sea reventaría el insert.
        return $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function sanitize(array $attributes): array
    {
        foreach (self::REDACTED as $field) {
            if (array_key_exists($field, $attributes)) {
                $attributes[$field] = '«omitido»';
            }
        }

        return $attributes;
    }
}
