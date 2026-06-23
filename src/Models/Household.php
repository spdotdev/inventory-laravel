<?php

namespace Spdotdev\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $join_code
 */
class Household extends Model
{
    protected $table = 'inventory_households';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'join_code',
    ];

    /**
     * Members of this household.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'inventory_household_user',
            'household_id',
            'user_id',
        )->withPivot('joined_at');
    }

    /**
     * @return HasMany<StorageLocation, $this>
     */
    public function storageLocations(): HasMany
    {
        return $this->hasMany(StorageLocation::class, 'household_id');
    }
}
