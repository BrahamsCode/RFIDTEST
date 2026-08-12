<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Paginación con `?page=` y `?per_page=` (máximo 200) y `meta.total`, según
 * las convenciones de `docs/06` §6.
 */
trait Paginates
{
    private const MAX_PER_PAGE = 200;

    private const DEFAULT_PER_PAGE = 50;

    /** @param Builder|QueryBuilder $query @return array<string, mixed> */
    protected function paginated($query, Request $request, ?callable $transform = null): array
    {
        $perPage = min(
            max((int) $request->integer('per_page', self::DEFAULT_PER_PAGE), 1),
            self::MAX_PER_PAGE,
        );

        $page = $query->paginate($perPage, ['*'], 'page', max($request->integer('page', 1), 1));

        $items = collect($page->items());

        return [
            'data' => $transform === null ? $items->all() : $items->map($transform)->all(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }
}
