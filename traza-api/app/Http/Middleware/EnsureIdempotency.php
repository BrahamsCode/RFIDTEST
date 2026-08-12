<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Problem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotencia por cabecera `Idempotency-Key` en los POST que mutan stock.
 *
 * Sin esto, un reintento por timeout de red vende, recibe o transfiere dos
 * veces la misma mercadería. La respuesta original se guarda y se reproduce
 * tal cual, para que el cliente vea lo mismo que habría visto.
 */
final class EnsureIdempotency
{
    private const TTL_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null || $key === '') {
            return $next($request);
        }

        $cacheKey = $this->cacheKey($request, $key);
        $stored = Cache::get($cacheKey);

        if ($stored !== null) {
            if ($stored['fingerprint'] !== $this->fingerprint($request)) {
                return Problem::conflict(
                    'La misma Idempotency-Key se está usando con un cuerpo distinto.'
                );
            }

            return response()->json($stored['body'], $stored['status'])
                ->header('Idempotent-Replay', 'true');
        }

        $response = $next($request);

        // Solo se memoriza lo que salió bien: un fallo debe poder reintentarse.
        if ($response instanceof Response && $response->isSuccessful()) {
            Cache::put($cacheKey, [
                'fingerprint' => $this->fingerprint($request),
                'status' => $response->getStatusCode(),
                'body' => json_decode((string) $response->getContent(), true),
            ], now()->addHours(self::TTL_HOURS));
        }

        return $response;
    }

    private function cacheKey(Request $request, string $key): string
    {
        return 'idempotency:'.sha1($request->path().'|'.$key);
    }

    private function fingerprint(Request $request): string
    {
        return sha1((string) $request->getContent());
    }
}
