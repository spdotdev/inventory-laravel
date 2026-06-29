<?php

namespace Spdotdev\Inventory\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property string|null $joined_at
 */
class HouseholdUserPivot extends Pivot
{
    protected $table = 'inventory_household_user';
}
