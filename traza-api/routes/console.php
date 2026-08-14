<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
| Tareas programadas.
|
| Todas llevan `onOneServer()`: con más de un contenedor de `scheduler` —lo
| normal al escalar— sin eso cada uno ejecutaría su copia, y dos rotaciones de
| partición simultáneas o dos exportaciones a la vez no acaban bien.
|
| Las horas son de madrugada en `America/Lima` porque el ciclo de una tienda
| ocupa la mañana y estas tareas hacen consultas pesadas.
*/

// Día 20 y no el 1: crea las particiones de los DOS meses siguientes, así que
// un fallo deja un mes de margen para enterarse. Ver `docs/05` §5.
Schedule::command('traza:rotate-partitions')
    ->monthlyOn(20, '03:00')
    ->timezone('America/Lima')
    ->onOneServer()
    ->emailOutputOnFailure(config('traza.ops.email'));

/*
 * Control de integridad de `docs/05` §6. Nunca se silencia: si la proyección
 * y los movimientos difieren, el número que ve el dueño de la tienda no es
 * real. `--quiet-when-clean` evita un correo diario cuando todo va bien; el
 * fallo sí avisa.
 */
Schedule::command('traza:check-projection --quiet-when-clean')
    ->dailyAt('03:30')
    ->timezone('America/Lima')
    ->onOneServer()
    ->emailOutputOnFailure(config('traza.ops.email'));

// Exportación a frío antes de que la partición entre en la ventana de purga.
// Se ejecuta el día 5, con quince días de holgura sobre la rotación del 20.
Schedule::command('traza:export-cold-reads')
    ->monthlyOn(5, '02:00')
    ->timezone('America/Lima')
    ->onOneServer()
    ->emailOutputOnFailure(config('traza.ops.email'));
