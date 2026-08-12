<?php

declare(strict_types=1);

namespace App\Domain\Tagging;

use App\Enums\MovementType;
use App\Enums\TagState;
use LogicException;

/**
 * Transiciones válidas del ciclo de vida de una prenda. Ver `docs/06` §3 y
 * `docs/02` §8.
 *
 * Dos transiciones aparecen aquí y no en la tabla resumen de `docs/02` §8,
 * porque `DEFAULT_TARGET` las exige:
 *
 *  - `en_stock → en_stock`: `cambio_zona` mueve una prenda entre zonas de la
 *    misma tienda sin cambiarle el estado.
 *  - `no_visto → vendido`: una prenda que el último ciclo no detectó puede
 *    venderse igualmente; estaba en la tienda, solo que el lector no la vio.
 *    Sin esta transición, la caja rechazaría una venta legítima.
 */
final class TagStateMachine
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'creado' => ['codificado', 'anulado'],
        'codificado' => ['en_stock', 'anulado'],
        'en_stock' => ['vendido', 'en_transito', 'no_visto', 'danado', 'baja', 'en_stock'],
        'en_transito' => ['en_stock', 'perdido'],
        'no_visto' => ['en_stock', 'perdido', 'vendido'],
        'perdido' => ['en_stock'],   // reaparición: genera alerta
        'vendido' => ['en_stock'],   // devolución de cliente
        'danado' => ['baja'],
        'baja' => [],
        'anulado' => [],
    ];

    /** Estado resultante por defecto según el tipo de movimiento. */
    private const DEFAULT_TARGET = [
        'tarado' => 'en_stock',
        'recepcion' => 'en_stock',
        'venta' => 'vendido',
        'devolucion_cliente' => 'en_stock',
        'devolucion_prov' => 'baja',
        'transferencia_out' => 'en_transito',
        'transferencia_in' => 'en_stock',
        'ajuste_positivo' => 'en_stock',
        'ajuste_negativo' => 'no_visto',
        'merma' => 'perdido',
        'dano' => 'danado',
        'cambio_zona' => 'en_stock',
        'reetiquetado' => 'baja',
        'anulacion' => 'anulado',
    ];

    public function canTransition(TagState $from, TagState $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], strict: true);
    }

    public function resolve(TagState $from, MovementType $type): TagState
    {
        return TagState::from(
            self::DEFAULT_TARGET[$type->value]
                ?? throw new LogicException("Sin estado destino para el movimiento {$type->value}.")
        );
    }

    /** @return list<TagState> */
    public function allowedFrom(TagState $from): array
    {
        return array_map(TagState::from(...), self::TRANSITIONS[$from->value] ?? []);
    }

    /**
     * Una reaparición es una prenda dada por perdida que vuelve a leerse.
     * No se bloquea, pero el sistema debe levantar una alerta: o la merma
     * estaba mal declarada, o alguien devolvió lo que se llevó.
     */
    public function isReappearance(TagState $from, TagState $to): bool
    {
        return $from === TagState::Perdido && $to === TagState::EnStock;
    }
}
