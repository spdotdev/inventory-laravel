<?php

namespace Spdotdev\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spdotdev\Inventory\Models\Product;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shelf_id' => $this->shelf_id,
            'name' => $this->name,
            'description' => $this->description,
            'code' => $this->code,
            'is_mandatory' => $this->is_mandatory,
            'image_url' => $this->image_url,
            'quantity' => $this->quantity,
            'low_stock_threshold' => $this->low_stock_threshold,
        ];
    }
}
