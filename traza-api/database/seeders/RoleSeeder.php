<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RoleCode;
use App\Models\Role;
use Illuminate\Database\Seeder;

final class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RoleCode::cases() as $code) {
            Role::updateOrCreate(
                ['code' => $code->value],
                ['name' => $code->label(), 'permissions' => $code->permissions()],
            );
        }
    }
}
