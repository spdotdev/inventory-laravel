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
 * @property int|null $restore_parent_id The location_id this shelf lived under
 *                                       before a location-delete's move_contents strategy reparented it here.
 *                                       Null unless a move is pending undo — RestoreController writes it back to
 *                                       location_id and clears it. Not to be confused with deletion_batch_id: a
 *                                       moved shelf is never soft-deleted, so it carries deletion_batch_id too
 *                                       (otherwise restore could never find it — see HierarchyDeleter).
 * @property-read int|null $products_count Only set when the query eager-loads
 *   it via withCount('products') (see ShelfController::index()); null
 *   otherwise. ShelfResource reads this with a ?? fallback to
 *   products()->count() — the docblock exists so PHPStan can catch a typo'd
 *   property name (e.g. product_count) that would otherwise silently fall
 *   through to that fallback with no static-analysis error.
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
        'restore_parent_id',
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
