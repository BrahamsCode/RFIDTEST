import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * Cliente de Reverb. Ver ADR-007 y `docs/06` §8.
 *
 * Reverb habla el protocolo de Pusher, así que se usa el mismo cliente
 * apuntando a nuestro servidor: no hay servicio externo ni datos saliendo
 * de la infraestructura propia.
 */

declare global {
  interface Window {
    Pusher: typeof Pusher;
  }
}

const key = import.meta.env.VITE_REVERB_KEY;

/**
 * Sin clave configurada no se crea el cliente. Es lo que permite que la
 * aplicación funcione con sondeo mientras Reverb no esté desplegado, en vez
 * de romperse al arrancar.
 */
export const echo = key
  ? createEcho(key)
  : null;

function createEcho(appKey: string): Echo<'reverb'> {
  window.Pusher = Pusher;

  return new Echo({
    broadcaster: 'reverb',
    key: appKey,
    wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
    enabledTransports: ['ws', 'wss'],
    // La autorización de canales privados va por la sesión de Sanctum.
    authEndpoint: `${import.meta.env.VITE_API_URL ?? ''}/broadcasting/auth`,
    withCredentials: true,
  });
}

export function isRealtimeEnabled(): boolean {
  return echo !== null;
}
