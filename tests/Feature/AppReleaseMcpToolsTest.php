<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Mcp\InventoryAdminServer;
use Spdotdev\Inventory\Mcp\Tools\CreateAppReleaseTool;
use Spdotdev\Inventory\Mcp\Tools\ListAppReleasesTool;
use Spdotdev\Inventory\Mcp\Tools\UpdateAppReleaseTool;
use Spdotdev\Inventory\Models\AppRelease;
use Spdotdev\Inventory\Tests\TestCase;

class AppReleaseMcpToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_app_releases_reports_drafts_and_published(): void
    {
        AppRelease::create([
            'version_code' => 21,
            'version_name' => '0.1.20',
            'changelog' => 'published',
            'download_url' => 'https://example.test/a.apk',
            'published_at' => now(),
        ]);
        AppRelease::create([
            'version_code' => 22,
            'version_name' => '0.1.21',
            'changelog' => 'draft',
            'download_url' => 'https://example.test/b.apk',
            'published_at' => null,
        ]);

        InventoryAdminServer::tool(ListAppReleasesTool::class)
            ->assertOk()
            ->assertSee('"version_code":21')
            ->assertSee('"version_code":22');
    }

    public function test_create_app_release_creates_a_draft_by_default(): void
    {
        InventoryAdminServer::tool(CreateAppReleaseTool::class, [
            'version_code' => 22,
            'version_name' => '0.1.21',
            'changelog' => 'new release',
            'download_url' => 'https://example.test/app.apk',
        ])->assertOk();

        $release = AppRelease::query()->where('version_code', 22)->firstOrFail();
        $this->assertNull($release->published_at);
    }

    public function test_create_app_release_rejects_breaking_without_min_supported_version_code(): void
    {
        InventoryAdminServer::tool(CreateAppReleaseTool::class, [
            'version_code' => 22,
            'version_name' => '0.1.21',
            'is_breaking' => true,
            'changelog' => 'breaking release',
            'download_url' => 'https://example.test/app.apk',
        ])->assertHasErrors();
    }

    public function test_update_app_release_rejects_breaking_without_min_supported_version_code(): void
    {
        $release = AppRelease::create([
            'version_code' => 22,
            'version_name' => '0.1.21',
            'changelog' => 'draft',
            'download_url' => 'https://example.test/app.apk',
            'published_at' => null,
        ]);

        InventoryAdminServer::tool(UpdateAppReleaseTool::class, [
            'id' => $release->id,
            'is_breaking' => true,
        ])->assertHasErrors();
    }

    public function test_update_app_release_can_publish_a_draft(): void
    {
        $release = AppRelease::create([
            'version_code' => 22,
            'version_name' => '0.1.21',
            'changelog' => 'draft',
            'download_url' => 'https://example.test/app.apk',
            'published_at' => null,
        ]);

        InventoryAdminServer::tool(UpdateAppReleaseTool::class, [
            'id' => $release->id,
            'publish' => true,
        ])->assertOk();

        $this->assertNotNull($release->fresh()->published_at);
    }
}
