<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Models\AppRelease;
use Spdotdev\Inventory\Tests\TestCase;

class AppReleaseModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_published_ignores_drafts_and_orders_by_version_code(): void
    {
        AppRelease::create([
            'version_code' => 20,
            'version_name' => '0.1.19',
            'changelog' => 'old',
            'download_url' => 'https://example.test/old.apk',
            'published_at' => now()->subDay(),
        ]);
        AppRelease::create([
            'version_code' => 22,
            'version_name' => '0.1.21',
            'changelog' => 'draft, not yet published',
            'download_url' => 'https://example.test/draft.apk',
            'published_at' => null,
        ]);
        $latest = AppRelease::create([
            'version_code' => 21,
            'version_name' => '0.1.20',
            'changelog' => 'newest published',
            'download_url' => 'https://example.test/latest.apk',
            'published_at' => now(),
        ]);

        $result = AppRelease::latestPublished();

        $this->assertNotNull($result);
        $this->assertSame($latest->id, $result->id);
    }

    public function test_latest_published_returns_null_when_no_release_is_published(): void
    {
        AppRelease::create([
            'version_code' => 22,
            'version_name' => '0.1.21',
            'changelog' => 'draft',
            'download_url' => 'https://example.test/draft.apk',
            'published_at' => null,
        ]);

        $this->assertNull(AppRelease::latestPublished());
    }
}
