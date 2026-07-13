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
     * This location's Unsorted shelf, created on first use — or RESTORED, if
     * an earlier one is sitting in the trash (soft-deleted while empty, not
     * yet purged).
     *
     * Lazy on purpose: a household that never deletes a non-empty shelf never
     * sees an Unsorted shelf at all. Creating one up-front for every location
     * would put an empty system shelf in front of every user to serve a case
     * most of them never hit.
     *
     * Find-live, then find-trashed-and-restore, then create: this is a
     * check-then-write, so it is wrapped in a transaction that locks THIS
     * location's row first. Two concurrent "delete shelf, keep products"
     * requests against the same location would otherwise both miss the
     * where('is_system', true) check and both write, producing two live
     * Unsorted shelves with products split across them. lockForUpdate()
     * serializes that: the second transaction blocks here until the first
     * commits, then its own re-check finds that row instead of duplicating it.
     *
     * The restore-before-create step exists for the exact same reason, one
     * door over: an earlier Unsorted shelf may already be soft-deleted (it was
     * empty, so deleting it needed no strategy — see ShelfController::destroy)
     * without having been purged yet. A plain find-live-or-create would not
     * see that trashed row at all and would mint a SECOND is_system shelf —
     * live at the same time the first is merely resting in the trash. That
     * second shelf state is a dead end for every surface that assumes at most
     * one is_system shelf per location: a later Undo of the first shelf's own
     * delete resurrects it too, and nothing downstream (move_contents,
     * RestoreController) is designed to reconcile two live Unsorted shelves in
     * one location — see docs/superpowers/sdd/final-review-fixes.md, C1.
     * Restoring the SAME row instead — clearing its deleted_at and
     * deletion_batch_id — means there is only ever at most one is_system row
     * (of either liveness) per location.
     *
     * A unique index on (location_id, is_system) does NOT substitute for any
     * of this: it collides with the rule that an empty Unsorted shelf may be
     * soft-deleted and later recreated, and adding deleted_at to the index
     * doesn't help on MySQL either — NULL deleted_at values compare as
     * distinct, so two live rows (both deleted_at = NULL) would still both
     * satisfy a unique constraint that includes it.
     *
     * Never broadcasts on its own. The create/restore write below is
     * deliberately event-free (a query-builder update fires no Eloquent
     * events at all; the create() branch explicitly suppresses its `created`
     * event) — see HierarchyDeleter's class docblock for why: this method is
     * called from inside a caller's larger operation, hoisted above that
     * caller's own transaction, so a broadcast fired from here would go out
     * even if the caller's transaction later rolled back. Every caller is
     * responsible for its own single, post-commit HouseholdChanged dispatch,
     * exactly like every other query-builder write in this package.
     */
    public function unsortedShelf(): Shelf
    {
        return DB::transaction(function () {
            self::query()->whereKey($this->getKey())->lockForUpdate()->first();

            $existing = $this->shelves()->where('is_system', true)->first();

            if ($existing !== null) {
                return $existing;
            }

            $trashed = $this->shelves()->onlyTrashed()->where('is_system', true)->orderBy('id')->first();

            if ($trashed !== null) {
                // withTrashed(), not query(): the row IS soft-deleted right
                // now, so the SoftDeletingScope's implicit `whereNull(deleted_at)`
                // on a plain query() would make this update match nothing.
                Shelf::withTrashed()->whereKey($trashed->getKey())->update([
                    'deleted_at' => null,
                    'deletion_batch_id' => null,
                ]);

                return $trashed->refresh();
            }

            return Shelf::withoutEvents(fn () => $this->shelves()->create([
                'name' => 'Unsorted',
                'is_system' => true,
                'position' => 0, // irrelevant: is_system sorts it last regardless
            ]));
        });
    }
}
