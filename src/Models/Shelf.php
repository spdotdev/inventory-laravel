<?php

namespace Spdotdev\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $location_id
 * @property string $name
 * @property int $position
 * @property bool $is_system
 * @property Carbon|null $deleted_at
 * @property string|null $deletion_batch_id
 */
class Shelf extends Model
{
    use SoftDeletes;

    protected $table = 'inventory_shelves';

    /** @var list<string> */
    protected $fillable = [
        'location_id',
        'name',
        'position',
        'is_system',
        'deletion_batch_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'position' => 0,
        'is_system' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_system' => 'boolean',
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
