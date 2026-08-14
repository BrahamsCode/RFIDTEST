<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Semilla de desarrollo. Equivale a `sql/seeds.sql`. Ver tarea 1.6.
 *
 * ⚠️ **No ejecutar en producción.** Crea usuarios con contraseña conocida y
 * más de mil tags con EPC del prefijo de ejemplo.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            ReferenceSeeder::class,
            TagSeeder::class,
            OperationsSeeder::class,
        ]);
    }
}
