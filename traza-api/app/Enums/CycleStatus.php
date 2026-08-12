<?php

declare(strict_types=1);

namespace App\Enums;

/** Espejo del ENUM `cycle_status` de PostgreSQL. */
enum CycleStatus: string
{
    case Borrador = 'borrador';
    case EnCurso = 'en_curso';
    case Pausado = 'pausado';
    case Conciliando = 'conciliando';
    case Cerrado = 'cerrado';
    case Cancelado = 'cancelado';

    /** Un ciclo cerrado o cancelado ya no admite escaneos. */
    public function acceptsScans(): bool
    {
        return in_array($this, [self::EnCurso, self::Pausado], strict: true);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Cerrado, self::Cancelado], strict: true);
    }
}
