<?php

namespace Spdotdev\Inventory\Enums;

/**
 * User-chosen household icon (Phase 2). Keys map to Material icons on the
 * Android client (HouseholdTheme.kt), which derives a fallback when null.
 */
enum HouseholdIcon: string
{
    case Home = 'home';
    case Kitchen = 'kitchen';
    case House = 'house';
    case Apartment = 'apartment';
    case Cottage = 'cottage';
    case Warehouse = 'warehouse';
    case Storefront = 'storefront';
    case Box = 'box';
}
