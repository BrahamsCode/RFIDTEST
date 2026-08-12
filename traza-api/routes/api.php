<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class)->name('api.v1.health');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('user', fn (Request $request) => $request->user())->name('api.v1.user');
    });
});
