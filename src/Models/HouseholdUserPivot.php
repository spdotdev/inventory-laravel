<?php

namespace Spdotdev\Inventory\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int|string $user_id
 * @property string|null $joined_at
 * @property string $role
 */
class HouseholdUserPivot extends Pivot
{
    protected $table = 'inventory_household_user';
}
