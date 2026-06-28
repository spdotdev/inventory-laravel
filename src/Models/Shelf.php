<?php

namespace Spdotdev\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $location_id
 * @property string $name
 * @property int $position
 */
class Shelf extends Model
{
    protected $table = 'inventory_shelves';

    /** @var list<string> */
    protected $fillable = [
        'location_id',
        'name',
        'position',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'position' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<StorageLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class, 'location_id');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'shelf_id');
    }
}
