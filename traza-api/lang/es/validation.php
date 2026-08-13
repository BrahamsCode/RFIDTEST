<?php

declare(strict_types=1);

/*
 * Mensajes de validación en español.
 *
 * Sin este fichero, `APP_LOCALE=es` hace que Laravel devuelva la clave cruda
 * (`validation.required`) en vez de un mensaje: la API entrega errores que
 * nadie puede leer y el frontend no tiene nada que mostrar.
 *
 * Solo se traducen las reglas que el proyecto usa. Añadir las que hagan falta
 * conforme aparezcan.
 */

return [
    'accepted' => 'Se debe aceptar :attribute.',
    'active_url' => ':attribute no es una URL válida.',
    'after' => ':attribute debe ser una fecha posterior a :date.',
    'array' => ':attribute debe ser una lista.',
    'before' => ':attribute debe ser una fecha anterior a :date.',
    'between' => [
        'array' => ':attribute debe tener entre :min y :max elementos.',
        'file' => ':attribute debe pesar entre :min y :max kilobytes.',
        'numeric' => ':attribute debe estar entre :min y :max.',
        'string' => ':attribute debe tener entre :min y :max caracteres.',
    ],
    'boolean' => ':attribute debe ser verdadero o falso.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'date' => ':attribute no es una fecha válida.',
    'different' => ':attribute y :other deben ser distintos.',
    'digits' => ':attribute debe tener :digits dígitos.',
    'email' => ':attribute debe ser un correo electrónico válido.',
    'exists' => ':attribute no existe.',
    'in' => ':attribute no es un valor admitido.',
    'integer' => ':attribute debe ser un número entero.',
    'max' => [
        'array' => ':attribute no puede tener más de :max elementos.',
        'numeric' => ':attribute no puede ser mayor que :max.',
        'string' => ':attribute no puede tener más de :max caracteres.',
    ],
    'min' => [
        'array' => ':attribute debe tener al menos :min elementos.',
        'numeric' => ':attribute debe ser al menos :min.',
        'string' => ':attribute debe tener al menos :min caracteres.',
    ],
    'numeric' => ':attribute debe ser un número.',
    'present' => ':attribute debe estar presente.',
    'prohibited' => ':attribute no está permitido.',
    'regex' => 'El formato de :attribute no es válido.',
    'required' => 'El campo :attribute es obligatorio.',
    'required_without' => 'El campo :attribute es obligatorio cuando falta :values.',
    'same' => ':attribute y :other deben coincidir.',
    'string' => ':attribute debe ser texto.',
    'unique' => ':attribute ya está en uso.',
    'uuid' => ':attribute debe ser un UUID válido.',

    'custom' => [],

    /*
     * Nombres legibles de los campos del dominio. Sin esto, el mensaje diría
     * "El campo epcs es obligatorio", que a un operario no le dice nada.
     */
    'attributes' => [
        'email' => 'el correo',
        'password' => 'la contraseña',
        'batch_id' => 'el identificador de lote',
        'device_code' => 'el código de dispositivo',
        'session_ref' => 'la referencia de sesión',
        'inventory_cycle_id' => 'el ciclo de inventario',
        'reads' => 'las lecturas',
        'reads.*.epc' => 'el EPC',
        'reads.*.tid' => 'el TID',
        'reads.*.read_at' => 'la fecha de lectura',
        'scans' => 'los escaneos',
        'scans.*.epc' => 'el EPC',
        'epcs' => 'los EPC',
        'epc' => 'el EPC',
        'new_epc' => 'el EPC sustituto',
        'location_id' => 'la ubicación',
        'to_location_id' => 'la ubicación de destino',
        'from_location_id' => 'la ubicación de origen',
        'to_zone_id' => 'la zona de destino',
        'product_variant_id' => 'la variante de producto',
        'expected_qty' => 'la cantidad esperada',
        'code' => 'el código',
        'reason' => 'el motivo',
        'lines' => 'las líneas',
        'zone_ids' => 'las zonas',
        'scope' => 'el alcance',
        'action' => 'la acción',
    ],
];
