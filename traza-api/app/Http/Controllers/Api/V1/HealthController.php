<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(static fn () => DB::select('SELECT 1')),
            'redis' => $this->check(static fn () => Redis::ping()),
        ];

        $healthy = ! in_array(false, $checks, strict: true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'version' => config('app.version', '0.1.0'),
            'epc_scheme' => config('traza.epc.scheme'),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    private function check(callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
