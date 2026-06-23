<?php

namespace Spdotdev\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spdotdev\Inventory\Models\Product;

/**
 * @mixin Product
 */
class SearchResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $location = $this->shelf->location;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'quantity' => $this->quantity,
            'location' => $location->name,
            'shelf' => $this->shelf->name,
            'path' => $location->name.' › '.$this->shelf->name,
        ];
    }
}
