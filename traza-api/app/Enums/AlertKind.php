<?php

declare(strict_types=1);

namespace App\Enums;

/** Espejo del ENUM `alert_kind` de PostgreSQL. */
enum AlertKind: string
{
    /** EPC que cruzó el portal sin registrarse la venta. */
    case SalidaNoVendida = 'salida_no_vendida';
    case EpcDesconocido = 'epc_desconocido';
    case EpcDuplicado = 'epc_duplicado';
    /** Mismo EPC leído con un TID distinto al registrado: posible clonación. */
    case TidDiscrepante = 'tid_discrepante';
    case ReaparicionPerdido = 'reaparicion_perdido';
    case LectorSinLatido = 'lector_sin_latido';
    case TasaLecturaBaja = 'tasa_lectura_baja';
    case StockNegativo = 'stock_negativo';
    case ReposicionSala = 'reposicion_sala';

    /** Severidad por defecto, de 1 (crítica) a 5 (informativa). */
    public function defaultSeverity(): int
    {
        return match ($this) {
            self::TidDiscrepante, self::StockNegativo => 1,
            self::SalidaNoVendida, self::LectorSinLatido => 2,
            self::ReaparicionPerdido, self::EpcDuplicado, self::TasaLecturaBaja => 3,
            self::EpcDesconocido, self::ReposicionSala => 4,
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::SalidaNoVendida => 'Salida sin venta registrada',
            self::EpcDesconocido => 'EPC desconocido',
            self::EpcDuplicado => 'EPC duplicado',
            self::TidDiscrepante => 'TID discrepante: posible clonación',
            self::ReaparicionPerdido => 'Reaparición de prenda dada por perdida',
            self::LectorSinLatido => 'Lector sin latido',
            self::TasaLecturaBaja => 'Tasa de lectura baja',
            self::StockNegativo => 'Stock negativo',
            self::ReposicionSala => 'Reposición de sala necesaria',
        };
    }
}
