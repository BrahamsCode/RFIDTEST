<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Http\JsonResponse;

/**
 * Respuestas de error en formato RFC 7807 (`application/problem+json`), que
 * es la convención que fija `docs/06` §6.
 */
final class Problem
{
    /** @param array<string, mixed> $extra */
    public static function make(
        int $status,
        string $title,
        ?string $detail = null,
        string $type = 'about:blank',
        array $extra = [],
    ): JsonResponse {
        return response()->json(
            array_filter([
                'type' => $type,
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
            ], static fn ($v) => $v !== null) + $extra,
            $status,
            ['Content-Type' => 'application/problem+json'],
        );
    }

    public static function conflict(string $detail): JsonResponse
    {
        return self::make(409, 'Conflicto con el estado actual', $detail, 'https://traza.pe/problems/conflict');
    }

    public static function unprocessable(string $detail): JsonResponse
    {
        return self::make(422, 'La operación no se puede procesar', $detail, 'https://traza.pe/problems/unprocessable');
    }

    public static function forbidden(string $detail): JsonResponse
    {
        return self::make(403, 'Acceso denegado', $detail, 'https://traza.pe/problems/forbidden');
    }
}
