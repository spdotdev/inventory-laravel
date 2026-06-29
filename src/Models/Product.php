<?php

namespace Spdotdev\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $shelf_id
 * @property string $name
 * @property string|null $description
 * @property string|null $code
 * @property bool $is_mandatory
 * @property string|null $image_url
 * @property int $quantity
 */
class Product extends Model
{
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
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity'     => 'integer',
            'is_mandatory' => 'boolean',
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
