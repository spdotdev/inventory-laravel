<?php

namespace Spdotdev\Inventory\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $version_code
 * @property string $version_name
 * @property bool $is_breaking
 * @property int|null $min_supported_version_code
 * @property string $changelog
 * @property string $download_url
 * @property Carbon|null $published_at
 */
class AppRelease extends Model
{
    protected $table = 'inventory_app_releases';

    /** @var list<string> */
    protected $fillable = [
        'version_code',
        'version_name',
        'is_breaking',
        'min_supported_version_code',
        'changelog',
        'download_url',
        'published_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_breaking' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /** @param Builder<AppRelease> $query */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('published_at');
    }

    public static function latestPublished(): ?self
    {
        return static::published()->orderByDesc('version_code')->first();
    }
}
