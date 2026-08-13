<?php

use App\Http\Middleware\AuthenticateDevice;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Problem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // El borde y el handheld se autentican con token de dispositivo, no
        // con sesión de usuario.
        $middleware->alias([
            'device' => AuthenticateDevice::class,
            'idempotency' => EnsureIdempotency::class,
        ]);

        $middleware->append(SecurityHeaders::class);

        // Sanctum en modo SPA: la web se autentica con la cookie de sesión,
        // no con tokens. Solo aplica a los dominios de SANCTUM_STATEFUL_DOMAINS.
        $middleware->statefulApi();

        // Límite de tasa: 300/min por usuario, 2000/min por dispositivo.
        // Ver AppServiceProvider::configureRateLimiting().
        $middleware->api(prepend: [
            ThrottleRequests::class.':api',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Los errores de la API se sirven en RFC 7807, que es la convención
        // que fija `docs/06` §6.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return Problem::make(
                    422,
                    'Los datos enviados no son válidos',
                    'Revise el campo "errors" para el detalle.',
                    'https://traza.pe/problems/validation',
                    ['errors' => $e->errors()],
                );
            }

            if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
                return Problem::make(404, 'No encontrado');
            }

            return null;
        });
    })->create();
