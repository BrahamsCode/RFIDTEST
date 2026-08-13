<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\IngestController;
use App\Http\Controllers\Api\V1\InventoryCycleController;
use App\Http\Controllers\Api\V1\MovementController;
use App\Http\Controllers\Api\V1\ReceivingOrderController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\TagAccessController;
use App\Http\Controllers\Api\V1\TagController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| Superficie REST de `docs/06` §6. Convenciones: versión en la ruta,
| paginación con page/per_page, errores RFC 7807 e Idempotency-Key en los
| POST que mutan stock.
*/

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class)->name('api.v1.health');

    // ---------------------------------------------------- token de dispositivo
    Route::middleware('device')->group(function (): void {
        Route::prefix('ingest')->group(function (): void {
            Route::post('reads', [IngestController::class, 'store'])->name('api.v1.ingest.reads');
            Route::post('heartbeat', [IngestController::class, 'heartbeat'])->name('api.v1.ingest.heartbeat');
        });

        Route::post('inventory-cycles/{inventoryCycle}/scans', [InventoryCycleController::class, 'storeScans'])
            ->name('api.v1.cycles.scans');

        // La clave maestra nunca sale del servidor: el handheld pide la
        // contraseña del EPC concreto que va a escribir.
        Route::get('tags/{epc}/access-password', [TagAccessController::class, 'show'])
            ->name('api.v1.tags.access-password');
    });

    // ------------------------------------------------------------- sesión web
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('user', fn (Request $request) => $request->user())->name('api.v1.user');

        // Tags
        Route::get('tags', [TagController::class, 'index'])->name('api.v1.tags.index');
        Route::get('tags/{epc}', [TagController::class, 'show'])->name('api.v1.tags.show');
        Route::get('tags/{epc}/history', [TagController::class, 'history'])->name('api.v1.tags.history');

        // Inventario
        Route::get('inventory-cycles', [InventoryCycleController::class, 'index'])->name('api.v1.cycles.index');
        Route::get('inventory-cycles/{inventoryCycle}', [InventoryCycleController::class, 'show'])->name('api.v1.cycles.show');
        Route::get('inventory-cycles/{inventoryCycle}/report', [InventoryCycleController::class, 'report'])->name('api.v1.cycles.report');
        Route::get('inventory-cycles/{inventoryCycle}/zone-performance', [InventoryCycleController::class, 'zonePerformance'])->name('api.v1.cycles.zones');
        Route::post('inventory-cycles/{inventoryCycle}/start', [InventoryCycleController::class, 'start'])->name('api.v1.cycles.start');
        Route::post('inventory-cycles/{inventoryCycle}/pause', [InventoryCycleController::class, 'pause'])->name('api.v1.cycles.pause');

        // Movimientos y recepción (lectura)
        Route::get('movements', [MovementController::class, 'index'])->name('api.v1.movements.index');
        Route::get('receiving-orders', [ReceivingOrderController::class, 'index'])->name('api.v1.receiving.index');
        Route::get('receiving-orders/{receivingOrder}', [ReceivingOrderController::class, 'show'])->name('api.v1.receiving.show');

        // Alertas
        Route::get('alerts', [AlertController::class, 'index'])->name('api.v1.alerts.index');
        Route::post('alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge'])->name('api.v1.alerts.ack');
        Route::post('alerts/{alert}/resolve', [AlertController::class, 'resolve'])->name('api.v1.alerts.resolve');

        // ------------------------------------------- POST que mutan stock
        // Llevan Idempotency-Key: un reintento por timeout de red no debe
        // vender, recibir ni transferir dos veces la misma mercadería.
        Route::middleware('idempotency')->group(function (): void {
            Route::post('inventory-cycles', [InventoryCycleController::class, 'store'])->name('api.v1.cycles.store');
            Route::post('inventory-cycles/{inventoryCycle}/close', [InventoryCycleController::class, 'close'])->name('api.v1.cycles.close');

            Route::post('receiving-orders', [ReceivingOrderController::class, 'store'])->name('api.v1.receiving.store');
            Route::post('receiving-orders/{receivingOrder}/receive', [ReceivingOrderController::class, 'receive'])->name('api.v1.receiving.receive');

            Route::post('sales', [SaleController::class, 'store'])->name('api.v1.sales.store');
            Route::post('sales/{sale}/return', [SaleController::class, 'returnItem'])->name('api.v1.sales.return');

            Route::post('movements/transfer', [MovementController::class, 'transfer'])->name('api.v1.movements.transfer');
            Route::post('movements/zone-change', [MovementController::class, 'zoneChange'])->name('api.v1.movements.zone');

            Route::post('tags/{epc}/replace', [TagController::class, 'replace'])->name('api.v1.tags.replace');
        });
    });
});
