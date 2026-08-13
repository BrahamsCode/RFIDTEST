<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Access password derivado de una clave maestra. Ver `docs/12` §3.
 *
 *     access_password(epc) = primeros_32_bits( HMAC-SHA256( clave_maestra, epc ) )
 *
 * Propiedades que dan valor a este diseño:
 *
 *  - No hay tabla de contraseñas que robar: se recalcula.
 *  - Conocer la contraseña de un tag no revela la de ningún otro.
 *  - El handheld NUNCA tiene la clave maestra: pide al servidor la
 *    contraseña del EPC concreto que va a escribir, y solo cuando la
 *    necesita.
 *
 * Consecuencia que hay que aceptar conscientemente: si se pierde la clave
 * maestra, ningún tag ya bloqueado podrá reescribirse jamás. Se custodia
 * como una clave de firma: copia sellada fuera de línea, en dos ubicaciones.
 */
final class TagAccessPasswordService
{
    /** Gen2 define el access password como 32 bits: 8 caracteres hex. */
    private const PASSWORD_HEX_LENGTH = 8;

    public function for(string $epc): string
    {
        $epc = strtoupper(trim($epc));

        if (preg_match('/^[0-9A-F]{8,48}$/', $epc) !== 1) {
            throw new RuntimeException("EPC inválido: {$epc}.");
        }

        $digest = hash_hmac('sha256', $epc, $this->masterKey());

        $password = strtoupper(substr($digest, 0, self::PASSWORD_HEX_LENGTH));

        /*
         * 00000000 es el valor por defecto de fábrica: un tag con esa
         * contraseña está de hecho sin proteger. La probabilidad es ínfima,
         * pero el caso existe y hay que resolverlo, no ignorarlo.
         */
        return $password === '00000000'
            ? strtoupper(substr(hash_hmac('sha256', $epc.':alt', $this->masterKey()), 0, self::PASSWORD_HEX_LENGTH))
            : $password;
    }

    /**
     * Kill password: aleatorio por tag y distinto del access password. Un
     * kill password por defecto permite a cualquiera desactivar la etiqueta
     * de forma irreversible.
     */
    public function killPasswordFor(string $epc): string
    {
        $digest = hash_hmac('sha256', $epc.':kill', $this->masterKey());

        return strtoupper(substr($digest, 0, self::PASSWORD_HEX_LENGTH));
    }

    public function isConfigured(): bool
    {
        return blank(config('traza.epc.access_master_key')) === false;
    }

    private function masterKey(): string
    {
        $key = (string) config('traza.epc.access_master_key');

        if ($key === '') {
            throw new RuntimeException(
                'No hay clave maestra configurada. Defina TRAZA_TAG_ACCESS_MASTER_KEY '
                .'(generar con: openssl rand -hex 32). Nunca debe estar en el repositorio.'
            );
        }

        return $key;
    }
}
