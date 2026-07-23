<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Models\AppRelease;
use Spdotdev\Inventory\Tests\TestCase;

class AppReleaseApiTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    private string $token = 'super-secret-admin-token';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('inventory.admin_token', $this->token);
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_public_endpoint_returns_null_when_no_release_is_published(): void
    {
        $this->getJson("{$this->base}/app-version")
            ->assertOk()
            ->assertJson(['data' => null]);
    }

    public function test_public_endpoint_returns_the_latest_published_release(): void
    {
        AppRelease::create([
            'version_code' => 21,
            'version_name' => '0.1.20',
            'changelog' => 'first',
            'download_url' => 'https://example.test/a.apk',
            'published_at' => now(),
        ]);

        $this->getJson("{$this->base}/app-version")
            ->assertOk()
            ->assertJsonPath('data.version_code', 21)
            ->assertJsonPath('data.version_name', '0.1.20');
    }

    public function test_public_endpoint_requires_no_auth(): void
    {
        $this->getJson("{$this->base}/app-version")->assertOk();
    }

    public function test_admin_create_requires_admin_token(): void
    {
        $this->postJson("{$this->base}/admin/app-releases", [
            'version_code' => 22,
            'version_name' => '0.1.21',
            'changelog' => 'x',
            'download_url' => 'https://example.test/b.apk',
        ])->assertStatus(401);
    }

    public function test_admin_can_create_a_draft_and_publish_it_later(): void
    {
        $created = $this->postJson("{$this->base}/admin/app-releases", [
            'version_code' => 22,
            'version_name' => '0.1.21',
            'changelog' => 'draft release',
            'download_url' => 'https://example.test/b.apk',
            'publish' => false,
        ], $this->auth())->assertStatus(201)->json('data');

        $this->assertNull($created['published_at']);
        // Not visible on the public endpoint yet.
        $this->getJson("{$this->base}/app-version")->assertJson(['data' => null]);

        $this->patchJson("{$this->base}/admin/app-releases/{$created['id']}", [
            'publish' => true,
        ], $this->auth())->assertOk();

        $this->getJson("{$this->base}/app-version")
            ->assertJsonPath('data.version_code', 22);
    }

    public function test_admin_can_list_all_releases_including_drafts(): void
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

        $this->getJson("{$this->base}/admin/app-releases", $this->auth())
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
