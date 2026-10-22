<?php

namespace App\Enums;

enum TenantRole: string
{
    case Owner = 'owner';
    case StoreManager = 'store_manager';
    case InventoryManager = 'inventory_manager';
    case Cashier = 'cashier';
    case MarketplaceManager = 'marketplace_manager';
    case Staff = 'staff';

    /** @return list<string> */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => self::all(),
            self::StoreManager => [
                'stores.view', 'inventory.view', 'inventory.create', 'inventory.adjust', 'inventory.cost.view',
                'listings.manage', 'prices.manage', 'pos.sell', 'pos.discount', 'pos.session', 'sales.view', 'sales.view_all',
                'sales.void', 'orders.view', 'orders.manage', 'ai.analyze', 'reports.view', 'purchase.receive', 'suppliers.manage',
            ],
            self::InventoryManager => [
                'stores.view', 'inventory.view', 'inventory.create', 'inventory.adjust', 'inventory.cost.view',
                'ai.analyze', 'suppliers.manage', 'purchase.receive',
            ],
            self::Cashier => ['inventory.view', 'pos.sell', 'pos.session', 'sales.view'],
            self::MarketplaceManager => ['stores.view', 'inventory.view', 'listings.manage', 'prices.manage', 'orders.view', 'orders.manage'],
            self::Staff => ['inventory.view', 'inventory.create', 'ai.analyze'],
        };
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [
            'stores.view', 'stores.manage', 'inventory.view', 'inventory.create', 'inventory.adjust', 'inventory.cost.view',
            'listings.manage', 'prices.manage', 'pos.sell', 'pos.discount', 'pos.session', 'sales.view', 'sales.view_all',
            'sales.void', 'orders.view', 'orders.manage', 'staff.manage', 'settings.manage', 'suppliers.manage',
            'purchase.receive', 'ai.analyze', 'reports.view',
        ];
    }

    /** Roles bound to a single store when membership.store_id is set. */
    public function canBeStoreBound(): bool
    {
        return $this !== self::Owner;
    }
}
