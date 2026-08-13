<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Los 7 roles de `docs/12` §2.
 *
 * La separación entre `tecnico` y los roles de tienda es deliberada y no se
 * debe relajar: `tecnico` puede cambiar la potencia de un lector pero no
 * declarar una prenda perdida, y `jefe_tienda` puede declarar merma pero no
 * tocar el perfil de lectura. Evita que un mismo actor pueda provocar un
 * fallo de lectura y justificar la desaparición resultante.
 */
enum RoleCode: string
{
    case Vendedor = 'vendedor';
    case Almacen = 'almacen';
    case JefeTienda = 'jefe_tienda';
    case SupervisorRegional = 'supervisor_regional';
    case Gerencia = 'gerencia';
    case Tecnico = 'tecnico';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Vendedor => 'Vendedor',
            self::Almacen => 'Almacén',
            self::JefeTienda => 'Jefe de tienda',
            self::SupervisorRegional => 'Supervisor regional',
            self::Gerencia => 'Gerencia',
            self::Tecnico => 'Técnico',
            self::Admin => 'Administrador',
        };
    }

    /**
     * Permisos del rol. `vendedor`, `almacen` y `jefe_tienda` son
     * acumulativos; los demás son conjuntos distintos, no escalones.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Vendedor => self::SALES,

            self::Almacen => [...self::SALES, ...self::WAREHOUSE],

            self::JefeTienda => [...self::SALES, ...self::WAREHOUSE, ...self::STORE_MANAGEMENT],

            self::SupervisorRegional => [
                ...self::SALES, ...self::WAREHOUSE, ...self::STORE_MANAGEMENT,
                'transfer.between-stores', 'adjustment.approve', 'location.all',
            ],

            // Solo lectura sobre todas las tiendas.
            self::Gerencia => ['stock.view', 'tag.view', 'alert.view', 'report.view', 'location.all'],

            // Sin permiso para ajustar stock. Es el punto del rol.
            self::Tecnico => ['device.manage', 'device.read-profile', 'tag.view', 'location.all'],

            self::Admin => ['*'],
        };
    }

    public function can(string $permission): bool
    {
        $permissions = $this->permissions();

        return in_array('*', $permissions, strict: true)
            || in_array($permission, $permissions, strict: true);
    }

    /** ¿El rol ve más allá de su tienda asignada? */
    public function isMultiLocation(): bool
    {
        return $this->can('location.all');
    }

    private const SALES = [
        'stock.view', 'tag.view', 'sale.create', 'sale.return', 'alert.view',
    ];

    private const WAREHOUSE = [
        'tag.commission', 'receiving.create', 'receiving.receive',
        'transfer.create', 'label.print', 'tag.replace', 'movement.zone-change',
    ];

    private const STORE_MANAGEMENT = [
        'cycle.create', 'cycle.close', 'adjustment.approve', 'report.view',
        'alert.manage',
    ];
}
