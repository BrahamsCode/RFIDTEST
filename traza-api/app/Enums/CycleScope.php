<?php

declare(strict_types=1);

namespace App\Enums;

/** Espejo del ENUM `cycle_scope` de PostgreSQL. */
enum CycleScope: string
{
    case Total = 'total';
    case Zona = 'zona';
    case Categoria = 'categoria';
    case Muestreo = 'muestreo';
}
