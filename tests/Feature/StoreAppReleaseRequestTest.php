<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Spdotdev\Inventory\Http\Requests\UpdateAppReleaseRequest;
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

    // No admin app-releases PATCH route exists yet (Task 3 hasn't landed), so this
    // exercises UpdateAppReleaseRequest's validation logic directly rather than via
    // a live route.
    public function test_update_rejects_min_supported_version_code_when_effective_is_breaking_false(): void
    {
        $existing = AppRelease::create([
            'version_code' => 21,
            'version_name' => '0.1.20',
            'is_breaking' => true,
            'min_supported_version_code' => 20,
            'changelog' => 'existing',
            'download_url' => 'https://example.test/existing.apk',
            'published_at' => now(),
        ]);

        $data = [
            'is_breaking' => false,
            'min_supported_version_code' => 5,
        ];

        $request = UpdateAppReleaseRequest::create('/', 'PATCH', $data);
        $request->setRouteResolver(function () use ($existing, $request) {
            $route = new Route('PATCH', '/', []);
            $route->bind($request);
            $route->setParameter('appRelease', $existing);

            return $route;
        });

        $validator = Validator::make($data, $request->rules());
        $request->withValidator($validator);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('min_supported_version_code', $validator->errors()->toArray());
    }

    // Exercises the $existing->min_supported_version_code fallback branch specifically:
    // the payload omits min_supported_version_code entirely, so $hasMin must be derived
    // from the existing record rather than from request input.
    public function test_update_rejects_is_breaking_false_when_existing_min_supported_version_code_is_set(): void
    {
        $existing = AppRelease::create([
            'version_code' => 21,
            'version_name' => '0.1.20',
            'is_breaking' => true,
            'min_supported_version_code' => 20,
            'changelog' => 'existing',
            'download_url' => 'https://example.test/existing.apk',
            'published_at' => now(),
        ]);

        $data = [
            'is_breaking' => false,
        ];

        $request = UpdateAppReleaseRequest::create('/', 'PATCH', $data);
        $request->setRouteResolver(function () use ($existing, $request) {
            $route = new Route('PATCH', '/', []);
            $route->bind($request);
            $route->setParameter('appRelease', $existing);

            return $route;
        });

        $validator = Validator::make($data, $request->rules());
        $request->withValidator($validator);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('min_supported_version_code', $validator->errors()->toArray());
    }
}
