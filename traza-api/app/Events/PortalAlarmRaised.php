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
