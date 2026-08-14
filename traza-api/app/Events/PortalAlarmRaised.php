<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Alarma de portal antihurto. Ver `docs/06` §8.
 *
 * Implementa ShouldBroadcastNow y no ShouldBroadcast: una alarma que llega
 * cuando la persona ya salió de la tienda no sirve de nada, así que no puede
 * esperar en la cola.
 */
final class PortalAlarmRaised implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $locationId,
        public readonly string $epc,
        public readonly float $confidence,
        public readonly ?int $tagId = null,
        public readonly ?string $productName = null,
    ) {}

    /**
     * Nombre con el que viaja el evento.
     *
     * Sin esto Laravel emite el nombre completo de la clase
     * (`App\Events\PortalAlarmRaised`), y el cliente escucha
     * `.PortalAlarmRaised` —el punto delante significa «nombre tal cual,
     * sin anteponer espacio de nombres»—. No coinciden, así que el evento
     * llega al navegador y el manejador nunca se ejecuta: la pantalla se
     * queda quieta sin ningún error a la vista.
     *
     * Se descubrió midiendo el retardo con un Reverb real.
     */
    public function broadcastAs(): string
    {
        return 'PortalAlarmRaised';
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("location.{$this->locationId}.alerts");
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'epc' => $this->epc,
            'confidence' => $this->confidence,
            'tag_id' => $this->tagId,
            'product_name' => $this->productName,
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
