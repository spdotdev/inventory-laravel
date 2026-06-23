<?php

namespace Spdotdev\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spdotdev\Inventory\Enums\StorageType;

/**
 * @property int $id
 * @property int $household_id
 * @property string $name
 * @property StorageType $type
 */
class StorageLocation extends Model
{
    protected $table = 'inventory_storage_locations';

    /** @var list<string> */
    protected $fillable = [
        'household_id',
        'name',
        'type',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => StorageType::class,
        ];
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class, 'household_id');
    }

    /**
     * @return HasMany<Shelf, $this>
     */
    public function shelves(): HasMany
    {
        return $this->hasMany(Shelf::class, 'location_id');
    }
}
