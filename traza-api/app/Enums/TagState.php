<?php

declare(strict_types=1);

namespace App\Enums;

/** Espejo del ENUM `tag_state` de PostgreSQL. Ver `docs/02` §8. */
enum TagState: string
{
    /** EPC reservado en base, sin soporte físico. */
    case Creado = 'creado';

    /** Escrito en un inlay, sin prenda asignada. */
    case Codificado = 'codificado';

    /** Asociado a prenda y presente en una ubicación. */
    case EnStock = 'en_stock';

    /** Enviado entre ubicaciones, no recibido. */
    case EnTransito = 'en_transito';

    /** No detectado en N ciclos consecutivos. */
    case NoVisto = 'no_visto';

    /** Declarado merma. */
    case Perdido = 'perdido';

    /** Salió por caja. */
    case Vendido = 'vendido';

    /** Prenda inservible. */
    case Danado = 'danado';

    /** Fuera de inventario definitivamente. */
    case Baja = 'baja';

    /** EPC descartado antes de usarse. */
    case Anulado = 'anulado';

    public function label(): string
    {
        return match ($this) {
            self::Creado => 'Creado',
            self::Codificado => 'Codificado',
            self::EnStock => 'En stock',
            self::EnTransito => 'En tránsito',
            self::NoVisto => 'No visto',
            self::Perdido => 'Perdido',
            self::Vendido => 'Vendido',
            self::Danado => 'Dañado',
            self::Baja => 'Baja',
            self::Anulado => 'Anulado',
        };
    }

    /** Un estado terminal no admite ninguna transición de salida. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Baja, self::Anulado], strict: true);
    }

    /** Estados que cuentan como existencias físicas reales. */
    public function countsAsStock(): bool
    {
        return in_array($this, [self::EnStock, self::NoVisto], strict: true);
    }
}
