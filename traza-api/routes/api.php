<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\IngestController;
use App\Http\Controllers\Api\V1\InventoryCycleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class)->name('api.v1.health');

    // Ingesta: autenticada con token de dispositivo, no de usuario.
    Route::middleware('device')->prefix('ingest')->group(function (): void {
        Route::post('reads', [IngestController::class, 'store'])->name('api.v1.ingest.reads');
        Route::post('heartbeat', [IngestController::class, 'heartbeat'])->name('api.v1.ingest.heartbeat');
    });

    // El handheld registra escaneos con su token de dispositivo.
    Route::middleware('device')->group(function (): void {
        Route::post('inventory-cycles/{inventoryCycle}/scans', [InventoryCycleController::class, 'storeScans'])
            ->name('api.v1.cycles.scans');
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('user', fn (Request $request) => $request->user())->name('api.v1.user');

        Route::get('inventory-cycles/{inventoryCycle}', [InventoryCycleController::class, 'show'])
            ->name('api.v1.cycles.show');
        Route::post('inventory-cycles/{inventoryCycle}/reconcile', [InventoryCycleController::class, 'reconcile'])
            ->name('api.v1.cycles.reconcile');
    });
});
