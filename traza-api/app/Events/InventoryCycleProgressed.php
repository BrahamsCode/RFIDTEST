<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Avance de un ciclo de inventario. Ver `docs/06` §8.
 *
 * Se difunde en cada lote de escaneos, no en cada EPC: durante un barrido de
 * 20 000 prendas eso serían 20 000 eventos y el navegador no daría abasto.
 */
final class InventoryCycleProgressed implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $cycleId,
        public readonly int $scanned,
        public readonly int $expected,
        public readonly ?int $zoneId = null,
    ) {}

    /**
     * Nombre con el que viaja el evento.
     *
     * Sin esto Laravel emite el nombre completo de la clase
     * (`App\Events\InventoryCycleProgressed`), y el cliente escucha
     * `.InventoryCycleProgressed` —el punto delante significa «nombre tal cual,
     * sin anteponer espacio de nombres»—. No coinciden, así que el evento
     * llega al navegador y el manejador nunca se ejecuta: la pantalla se
     * queda quieta sin ningún error a la vista.
     *
     * Se descubrió midiendo el retardo con un Reverb real.
     */
    public function broadcastAs(): string
    {
        return 'InventoryCycleProgressed';
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("inventory-cycle.{$this->cycleId}");
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'scanned' => $this->scanned,
            'expected' => $this->expected,
            'progress' => $this->expected > 0
                ? round(100 * $this->scanned / $this->expected, 1)
                : 0,
            'zone_id' => $this->zoneId,
        ];
    }
}
