<?php

namespace Spdotdev\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spdotdev\Inventory\Events\HouseholdChanged;

/**
 * @property int $id
 * @property int $shelf_id
 * @property string $name
 * @property string|null $description
 * @property string|null $code
 * @property bool $is_mandatory
 * @property bool $is_starred
 * @property string|null $image_url
 * @property int $quantity
 * @property int|null $low_stock_threshold
 * @property Carbon|null $deleted_at
 * @property string|null $deletion_batch_id
 * @property int|null $deleted_by The inventory_users.id that deleted this row —
 *                                see StorageLocation's $deleted_by docblock for the full reasoning.
 * @property int|null $restore_parent_id The shelf_id this product lived under
 *                                       before a move_products/unsort_products strategy reassigned it. Null
 *                                       unless a move is pending undo — RestoreController writes it back to
 *                                       shelf_id and clears it. Not to be confused with deletion_batch_id: a
 *                                       moved product is never soft-deleted, so it carries deletion_batch_id too
 *                                       (otherwise restore could never find it — see HierarchyDeleter).
 */
class Product extends Model
{
    use SoftDeletes;

    protected $table = 'inventory_products';

    /** @var list<string> */
    protected $fillable = [
        'shelf_id',
        'name',
        'description',
        'code',
        'is_mandatory',
        'is_starred',
        'image_url',
        'quantity',
        'low_stock_threshold',
        'deletion_batch_id',
        'deleted_by',
        'restore_parent_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'is_mandatory' => 'boolean',
            'is_starred' => 'boolean',
            'low_stock_threshold' => 'integer',
        ];
    }

    /**
     * Atomic, clamped stock increment shared by the API and web controllers.
     * Concurrency-safe (a silently dropped stock delta is not the
     * "last-write-wins on a full-record edit" the concurrency rule sanctions)
     * and clamped to $max so a near-cap quantity plus repeated/large adds can't
     * push past the invariant — or the unsignedInteger column ceiling
     * (SQLSTATE 22003 → 500). Portable CASE, not MySQL-only LEAST.
     *
     * A query-builder update() fires no Eloquent events, so
     * BroadcastHouseholdChange never sees this write — broadcastChange()
     * below dispatches HouseholdChanged explicitly, exactly like every other
     * query-builder write in this package (see HierarchyDeleter,
     * LocationController::reorder). Placed here rather than in each caller so
     * every surface (API, web, and any future one) gets it for free instead of
     * having to remember.
     */
    public function addStock(int $amount, int $max): void
    {
        static::query()->whereKey($this->getKey())->update([
            'quantity' => DB::raw(
                'CASE WHEN quantity + '.$amount.' > '.$max.' THEN '.$max.' ELSE quantity + '.$amount.' END',
            ),
        ]);
        $this->refresh();
        $this->broadcastChange();
    }

    /**
     * Atomic stock decrement, floored at 0 (D-012; the row is retained as
     * out-of-stock). Compares BEFORE subtracting (`quantity < N`): the column is
     * BIGINT UNSIGNED, so `quantity - N` with N > quantity underflows and MySQL
     * (strict mode) throws "value out of range". Portable CASE, not GREATEST.
     *
     * See addStock()'s docblock for why this dispatches HouseholdChanged itself.
     */
    public function removeStock(int $amount): void
    {
        static::query()->whereKey($this->getKey())->update([
            'quantity' => DB::raw(
                'CASE WHEN quantity < '.$amount.' THEN 0 ELSE quantity - '.$amount.' END',
            ),
        ]);
        $this->refresh();
        $this->broadcastChange();
    }

    /**
     * @return BelongsTo<Shelf, $this>
     */
    public function shelf(): BelongsTo
    {
        return $this->belongsTo(Shelf::class, 'shelf_id');
    }

    /**
     * The household this product belongs to, walked through shelf -> location
     * (a Product carries no household_id of its own). Null if either link is
     * missing — an orphaned FK, not something to guess at. Public so both
     * BroadcastHouseholdChange (for ordinary Eloquent-event mutations) and
     * addStock/removeStock (which bypass those events entirely) share the one
     * walk instead of keeping two copies to drift.
     */
    public function householdId(): ?int
    {
        return $this->shelf?->location?->household_id !== null
            ? (int) $this->shelf->location->household_id
            : null;
    }

    /**
     * The single post-write broadcast point for the query-builder stock
     * mutations above. Runs after the update has already executed (and, for
     * every current caller, after it has committed — neither addStock() nor
     * removeStock() opens a transaction, and no caller wraps them in one), so
     * a failed update never reaches this line and nothing is broadcast for a
     * write that didn't happen.
     */
    private function broadcastChange(): void
    {
        $householdId = $this->householdId();

        if ($householdId !== null) {
            HouseholdChanged::dispatch($householdId);
        }
    }
}
