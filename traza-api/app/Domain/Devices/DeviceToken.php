<?php

declare(strict_types=1);

namespace App\Domain\Devices;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Emisión y verificación del token de un dispositivo.
 *
 * `docs/12` §4 exige que el token viaje hasheado en `devices.api_token_hash`
 * y no fija el algoritmo. Aquí se usa **SHA-256 y no bcrypt**, que es lo
 * contrario de lo que uno haría con la contraseña de una persona, y conviene
 * explicar por qué.
 *
 * bcrypt es lento a propósito: encarece el ataque por fuerza bruta contra
 * contraseñas que las personas eligen mal. Un token de dispositivo no lo
 * elige nadie: son 64 caracteres aleatorios, del orden de 380 bits. No hay
 * fuerza bruta que valga contra eso ni a un billón de intentos por segundo,
 * así que el estiramiento de clave no aporta seguridad — solo coste.
 *
 * Y el coste era real y estaba en el peor sitio posible. Medido en esta
 * máquina, bcrypt con coste 12 tarda **231 ms**, y se ejecutaba en **cada**
 * petición de ingesta. Cada borde vacía su buffer una vez por segundo: diez
 * tiendas son 2,3 s de CPU por segundo quemados solo en comprobar
 * contraseñas, más de un núcleo entero sin hacer nada útil. Al límite que
 * fija `docs/06` §6 para dispositivos —2000 por minuto— harían falta unos
 * ocho núcleos dedicados a hashear.
 *
 * El token de alta de `DeviceEnrollmentService` ya usaba SHA-256 con
 * `hash_equals`. Esto alinea el token de API con ese mismo criterio.
 */
final class DeviceToken
{
    /** Longitud del token en claro. 64 caracteres ≈ 380 bits de entropía. */
    private const LENGTH = 64;

    /** Token nuevo en claro. Solo se puede ver una vez; se guarda su hash. */
    public static function generate(): string
    {
        return Str::random(self::LENGTH);
    }

    /** Hash que se persiste en `devices.api_token_hash`. */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * ¿Corresponde el token a este hash?
     *
     * Con `hash_equals` para que el tiempo de comparación no dependa de
     * cuántos caracteres se acertaron.
     */
    public static function matches(string $token, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            return false;
        }

        if (self::isLegacy($hash)) {
            return Hash::check($token, $hash);
        }

        return hash_equals($hash, self::hash($token));
    }

    /**
     * ¿Es un hash bcrypt de los de antes?
     *
     * Los dispositivos dados de alta antes de este cambio conservan su hash
     * bcrypt y siguen funcionando: se comprueban por el camino lento y se
     * reescriben al vuelo la primera vez que aciertan. Cuando no quede
     * ninguno, este camino deja de ejecutarse solo.
     */
    public static function isLegacy(string $hash): bool
    {
        return str_starts_with($hash, '$2y$')
            || str_starts_with($hash, '$2a$')
            || str_starts_with($hash, '$argon2');
    }
}
