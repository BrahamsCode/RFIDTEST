<?php

declare(strict_types=1);

return [
    'host' => env('MQTT_HOST', 'mosquitto'),
    'port' => (int) env('MQTT_PORT', 1883),
    'username' => env('MQTT_USERNAME'),
    'password' => env('MQTT_PASSWORD'),
    'tls' => (bool) env('MQTT_TLS', false),
    'ca_file' => env('MQTT_CA_FILE'),
    'client_id' => env('MQTT_CLIENT_ID', 'traza-api'),
];
