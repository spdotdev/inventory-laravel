<?php

namespace Spdotdev\Inventory\Enums;

/**
 * User-chosen household accent (Phase 2). Keys — not hex values — so each
 * client renders them in its own theme; they mirror the Android client's
 * derived palette (HouseholdTheme.kt), which stays the fallback when null.
 */
enum HouseholdColor: string
{
    case Sky = 'sky';
    case Teal = 'teal';
    case Indigo = 'indigo';
    case Pink = 'pink';
    case Amber = 'amber';
    case Green = 'green';
    case Violet = 'violet';
    case Orange = 'orange';
}
