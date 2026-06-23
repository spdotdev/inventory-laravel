<?php

namespace Spdotdev\Inventory\Enums;

enum StorageType: string
{
    case Freezer = 'freezer';
    case Fridge = 'fridge';
    case Pantry = 'pantry';
    case Other = 'other';
}
