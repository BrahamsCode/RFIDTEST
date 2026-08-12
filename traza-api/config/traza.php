<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Codificación EPC
    |--------------------------------------------------------------------------
    | El esquema y el prefijo GS1 se fijan en la tarea 0.2 y no deben cambiarse
    | una vez emitidas etiquetas: un cambio invalida todo lo ya tarado.
    */
    'epc' => [
        'scheme' => env('TRAZA_EPC_SCHEME', 'sgtin-96'),
        'gs1_company_prefix' => env('TRAZA_GS1_COMPANY_PREFIX'),
        'filter' => (int) env('TRAZA_EPC_FILTER', 1),
        'mask' => strtoupper((string) env('TRAZA_EPC_MASK', '3035D9')),
        'test_prefix' => strtoupper((string) env('TRAZA_EPC_TEST_PREFIX', 'FFFF')),
        'access_master_key' => env('TRAZA_TAG_ACCESS_MASTER_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ciclos de inventario
    |--------------------------------------------------------------------------
    | missing_threshold = 1 borra stock real ante un simple fallo de lectura;
    | 5 detecta la merma demasiado tarde. Ver docs/05 §2.4.
    */
    'inventory' => [
        'missing_threshold' => (int) env('TRAZA_MISSING_THRESHOLD', 2),
        'autoclose_minutes' => (int) env('TRAZA_CYCLE_AUTOCLOSE', 240),
        'min_accuracy' => (float) env('TRAZA_MIN_ACCURACY', 95.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Portal antihurto
    |--------------------------------------------------------------------------
    */
    'portal' => [
        'alarm_enabled' => (bool) env('TRAZA_PORTAL_ALARM', true),
        'sale_grace_seconds' => (int) env('TRAZA_PORTAL_GRACE', 120),
        'min_confidence' => (float) env('TRAZA_PORTAL_CONFIDENCE', 0.7),
        'ignore_unknown' => (bool) env('TRAZA_PORTAL_IGNORE_UNKNOWN', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ingesta de lecturas
    |--------------------------------------------------------------------------
    */
    'ingest' => [
        'max_batch' => (int) env('TRAZA_MAX_BATCH', 1000),
        'reads_retention_months' => (int) env('TRAZA_READS_RETENTION', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Operaciones
    |--------------------------------------------------------------------------
    */
    'ops' => [
        'email' => env('TRAZA_OPS_EMAIL'),
        'timezone' => 'America/Lima',
    ],

];
