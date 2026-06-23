<?php

namespace Spdotdev\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $shelf_id
 * @property string $name
 * @property int $quantity
 */
class Product extends Model
{
    protected $table = 'inventory_products';

    /** @var list<string> */
    protected $fillable = [
        'shelf_id',
        'name',
        'quantity',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Shelf, $this>
     */
    public function shelf(): BelongsTo
    {
        return $this->belongsTo(Shelf::class, 'shelf_id');
    }
}
