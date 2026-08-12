<?php

declare(strict_types=1);

namespace App\Enums;

/** Espejo del ENUM `device_kind` de PostgreSQL. */
enum DeviceKind: string
{
    case Handheld = 'handheld';
    case LectorFijo = 'lector_fijo';
    case Impresora = 'impresora';
    case Edge = 'edge';

    /** Los dispositivos que envían lecturas de tags a la ingesta. */
    public function readsTags(): bool
    {
        return in_array($this, [self::Handheld, self::LectorFijo, self::Edge], strict: true);
    }
}
