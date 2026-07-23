<?php

namespace Spdotdev\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spdotdev\Inventory\Models\AppRelease;

/**
 * @mixin AppRelease
 */
class AppReleaseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version_code' => $this->version_code,
            'version_name' => $this->version_name,
            'is_breaking' => $this->is_breaking,
            'min_supported_version_code' => $this->min_supported_version_code,
            'changelog' => $this->changelog,
            'download_url' => $this->download_url,
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
