<?php

namespace Spdotdev\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $shelf_id
 * @property string $name
 * @property string|null $description
 * @property string|null $code
 * @property bool $is_mandatory
 * @property string|null $image_url
 * @property int $quantity
 * @property int|null $low_stock_threshold
 * @property Carbon|null $deleted_at
 * @property string|null $deletion_batch_id
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
        'image_url',
        'quantity',
        'low_stock_threshold',
        'deletion_batch_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'is_mandatory' => 'boolean',
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
     */
    public function addStock(int $amount, int $max): void
    {
        static::query()->whereKey($this->getKey())->update([
            'quantity' => DB::raw(
                'CASE WHEN quantity + '.$amount.' > '.$max.' THEN '.$max.' ELSE quantity + '.$amount.' END',
            ),
        ]);
        $this->refresh();
    }

    /**
     * Atomic stock decrement, floored at 0 (D-012; the row is retained as
     * out-of-stock). Compares BEFORE subtracting (`quantity < N`): the column is
     * BIGINT UNSIGNED, so `quantity - N` with N > quantity underflows and MySQL
     * (strict mode) throws "value out of range". Portable CASE, not GREATEST.
     */
    public function removeStock(int $amount): void
    {
        static::query()->whereKey($this->getKey())->update([
            'quantity' => DB::raw(
                'CASE WHEN quantity < '.$amount.' THEN 0 ELSE quantity - '.$amount.' END',
            ),
        ]);
        $this->refresh();
    }

    /**
     * @return BelongsTo<Shelf, $this>
     */
    public function shelf(): BelongsTo
    {
        return $this->belongsTo(Shelf::class, 'shelf_id');
    }
}
