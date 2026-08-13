<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

/*
| Acceso de la aplicación web. Va en las rutas web y no en las de API porque
| Sanctum en modo SPA se apoya en la sesión con cookie, que necesita el grupo
| de middleware `web`.
*/

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:6,1')
    ->name('login');

Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/', fn () => response()->json([
    'name' => 'TRAZA API',
    'docs' => 'Ver README.md y docs/06-backend-laravel.md',
]));
