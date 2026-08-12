<?php

declare(strict_types=1);

namespace App\Enums;

/** Espejo del ENUM `movement_type` de PostgreSQL. */
enum MovementType: string
{
    /** Alta: EPC asociado a prenda. */
    case Tarado = 'tarado';
    case Recepcion = 'recepcion';
    case Venta = 'venta';
    case DevolucionCliente = 'devolucion_cliente';
    case DevolucionProveedor = 'devolucion_prov';
    case TransferenciaOut = 'transferencia_out';
    case TransferenciaIn = 'transferencia_in';
    /** Aparición en conteo. */
    case AjustePositivo = 'ajuste_positivo';
    /** Desaparición en conteo. */
    case AjusteNegativo = 'ajuste_negativo';
    case Merma = 'merma';
    case Dano = 'dano';
    /** Movimiento interno entre zonas de la misma ubicación. */
    case CambioZona = 'cambio_zona';
    case Reetiquetado = 'reetiquetado';
    case Anulacion = 'anulacion';

    /** Signo del movimiento sobre las existencias: +1 entrada, -1 salida, 0 neutro. */
    public function sign(): int
    {
        return match ($this) {
            self::Tarado, self::Recepcion, self::DevolucionCliente,
            self::TransferenciaIn, self::AjustePositivo => 1,

            self::Venta, self::DevolucionProveedor, self::TransferenciaOut,
            self::AjusteNegativo, self::Merma, self::Dano,
            self::Reetiquetado, self::Anulacion => -1,

            // Cambiar de zona no altera el total de la ubicación.
            self::CambioZona => 0,
        };
    }

    /** Movimientos que un operario no puede originar sin autorización. */
    public function requiresApproval(): bool
    {
        return in_array($this, [
            self::AjustePositivo,
            self::AjusteNegativo,
            self::Merma,
        ], strict: true);
    }
}
