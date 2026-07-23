<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Models\AppRelease;
use Spdotdev\Inventory\Tests\TestCase;

class StoreAppReleaseRequestTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_breaking_release_requires_min_supported_version_code(): void
    {
        $this->postJson('http://inventory.test/api/v1/admin/app-releases', [
            'version_code' => 22,
            'version_name' => '0.1.21',
            'is_breaking' => true,
            'changelog' => 'breaking change',
            'download_url' => 'https://example.test/app.apk',
        ], $this->auth())
            ->assertStatus(422)
            ->assertJsonValidationErrors('min_supported_version_code');
    }

    public function test_non_breaking_release_rejects_min_supported_version_code(): void
    {
        $this->postJson('http://inventory.test/api/v1/admin/app-releases', [
            'version_code' => 22,
            'version_name' => '0.1.21',
            'is_breaking' => false,
            'min_supported_version_code' => 20,
            'changelog' => 'optional change',
            'download_url' => 'https://example.test/app.apk',
        ], $this->auth())
            ->assertStatus(422)
            ->assertJsonValidationErrors('min_supported_version_code');
    }

    public function test_duplicate_version_code_is_rejected(): void
    {
        AppRelease::create([
            'version_code' => 21,
            'version_name' => '0.1.20',
            'changelog' => 'existing',
            'download_url' => 'https://example.test/existing.apk',
            'published_at' => now(),
        ]);

        $this->postJson('http://inventory.test/api/v1/admin/app-releases', [
            'version_code' => 21,
            'version_name' => '0.1.20-dup',
            'changelog' => 'dup',
            'download_url' => 'https://example.test/dup.apk',
        ], $this->auth())
            ->assertStatus(422)
            ->assertJsonValidationErrors('version_code');
    }
}
