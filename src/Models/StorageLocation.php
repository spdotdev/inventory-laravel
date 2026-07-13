<?php

namespace Spdotdev\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spdotdev\Inventory\Enums\StorageType;

/**
 * @property int $id
 * @property int $household_id
 * @property string $name
 * @property StorageType $type
 * @property int $position
 * @property bool $is_system
 * @property Carbon|null $deleted_at
 * @property string|null $deletion_batch_id
 */
class StorageLocation extends Model
{
    use SoftDeletes;

    protected $table = 'inventory_storage_locations';

    /** @var list<string> */
    protected $fillable = [
        'household_id',
        'name',
        'type',
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
            'type' => StorageType::class,
            'position' => 'integer',
            'is_system' => 'boolean',
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

    /**
     * Products across all of this location's shelves. Backs the "does this
     * location still hold anything?" check the delete strategies depend on.
     *
     * @return HasManyThrough<Product, Shelf, $this>
     */
    public function products(): HasManyThrough
    {
        return $this->hasManyThrough(
            Product::class,
            Shelf::class,
            'location_id', // FK on shelves
            'shelf_id',    // FK on products
            'id',
            'id',
        );
    }

    /**
     * This location's Unsorted shelf, created on first use.
     *
     * Lazy on purpose: a household that never deletes a non-empty shelf never
     * sees an Unsorted shelf at all. Creating one up-front for every location
     * would put an empty system shelf in front of every user to serve a case
     * most of them never hit.
     *
     * The find-then-create below is a check-then-insert, so it is wrapped in a
     * transaction that locks THIS location's row first. Two concurrent
     * "delete shelf, keep products" requests against the same location would
     * otherwise both SELECT-miss the where('is_system', true) check and both
     * INSERT, producing two live Unsorted shelves with products split across
     * them. lockForUpdate() serializes that: the second transaction blocks
     * here until the first commits its Unsorted shelf, then its own re-check
     * finds that row instead of duplicating it.
     *
     * A unique index on (location_id, is_system) does NOT substitute for
     * this: it collides with the rule that an empty Unsorted shelf may be
     * soft-deleted and later recreated, and adding deleted_at to the index
     * doesn't help on MySQL either — NULL deleted_at values compare as
     * distinct, so two live rows (both deleted_at = NULL) would still both
     * satisfy a unique constraint that includes it.
     */
    public function unsortedShelf(): Shelf
    {
        return DB::transaction(function () {
            self::query()->whereKey($this->getKey())->lockForUpdate()->first();

            $existing = $this->shelves()->where('is_system', true)->first();

            if ($existing !== null) {
                return $existing;
            }

            return $this->shelves()->create([
                'name' => 'Unsorted',
                'is_system' => true,
                'position' => 0, // irrelevant: is_system sorts it last regardless
            ]);
        });
    }
}
