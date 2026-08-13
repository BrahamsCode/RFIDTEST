<?php

declare(strict_types=1);

return [
    /*
     * `sanctum/csrf-cookie` y `login` tienen que estar aquí, o la SPA no
     * puede autenticarse: el navegador bloquea la petición antes de que
     * llegue a Laravel.
     */
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout'],

    'allowed_methods' => ['*'],

    // Nunca '*' con credenciales: el navegador lo rechaza y, si lo aceptara,
    // cualquier sitio podría hacer peticiones con la sesión del usuario.
    'allowed_origins' => array_filter([
        env('FRONTEND_URL', 'http://localhost:5173'),
    ]),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Idempotent-Replay'],

    'max_age' => 0,

    'supports_credentials' => true,
];
