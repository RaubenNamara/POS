<?php

namespace App\Support;

/**
 * Role -> ability map. One place, used by Gate (web and mobile share the same API) and
 * returned to clients so they can hide what the server would refuse anyway.
 */
final class Permissions
{
    public const ROLES = ['owner', 'manager', 'cashier', 'stock_manager', 'customer'];
    public const STAFF_ROLES = ['owner', 'manager', 'cashier', 'stock_manager'];

    public const ABILITIES = [
        'products.view', 'products.manage',
        'inventory.view', 'inventory.adjust',
        'suppliers.manage', 'purchases.manage', 'purchases.receive',
        'pos.sell', 'sales.view_all', 'sales.refund', 'orders.manage',
        'customers.view', 'customers.manage', 'customers.collect',
        'expenses.manage',
        'reports.operational', 'reports.financial',
        'users.manage', 'settings.manage', 'offline.resolve', 'marketing.manage',
    ];

    private const MAP = [
        'manager' => [
            'products.view', 'products.manage', 'inventory.view', 'inventory.adjust',
            'suppliers.manage', 'purchases.manage', 'purchases.receive',
            'pos.sell', 'sales.view_all', 'sales.refund', 'orders.manage',
            'customers.view', 'customers.manage', 'customers.collect',
            'expenses.manage', 'reports.operational', 'offline.resolve', 'marketing.manage',
        ],
        'cashier' => [
            'products.view', 'inventory.view', 'pos.sell',
            'customers.view', 'customers.manage', 'customers.collect',
        ],
        'stock_manager' => [
            'products.view', 'products.manage', 'inventory.view', 'inventory.adjust',
            'purchases.receive', 'reports.operational',
        ],
        'customer' => [],
    ];

    /** @return list<string> */
    public static function forRole(string $role): array
    {
        if ($role === 'owner') {
            return self::ABILITIES;
        }

        return self::MAP[$role] ?? [];
    }

    public static function allows(string $role, string $ability): bool
    {
        return in_array($ability, self::forRole($role), true);
    }
}
