# App Update Notifications Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the Inventory Android app tell users about new releases — a popup on app open (optional vs hard-blocking breaking updates) and a notification when the app is closed — sourced from a new Laravel `app_releases` table that Claude can publish to via `inventory-mcp` tools.

**Architecture:** Laravel exposes one public read endpoint (`GET /api/v1/app-version`) and three admin-token-gated write endpoints under `/api/v1/admin/app-releases`; `inventory-mcp` wraps the admin endpoints as three new tools; Android polls the public endpoint on every app open (dialog) and every 24h via WorkManager (notification), classifying the result as none/optional/breaking via a shared comparator.

**Tech Stack:** Laravel 11 (PHP), PHPUnit; Node/TypeScript MCP SDK (`@modelcontextprotocol/sdk`), zod; Kotlin/Compose, Hilt, Retrofit, WorkManager (`androidx.work:work-runtime-ktx`, `androidx.hilt:hilt-work`).

## Global Constraints

- Backend: table name `inventory_app_releases` (package convention — every table is `inventory_`-prefixed). Namespace `Spdotdev\Inventory\...` throughout.
- Backend: admin writes go through the existing `EnsureAdminToken` middleware (`inventory.admin` alias) + `throttle:inventory-admin`, same as `AdminController` — no new auth mechanism.
- Backend: routes live under `Route::domain(config('inventory.domain'))->prefix('api/v1')->middleware('api')` in `routes/api.php`, matching every existing route.
- MCP: new tools call the shared `adminFetch()` helper already defined in `src/server.ts` — no new HTTP client.
- MCP: `test/server.test.mjs` has an `EXPECTED_TOOLS` list asserting the exact tool set by name — every new tool must be added there or the test suite fails by design.
- Android: package root `app/src/main/java/dev/scuttle/inventory/`; Application class is `InventoryApp.kt` (not `InventoryApplication`); minSdk 26, targetSdk 36, compileSdk 37, Kotlin JVM target 17.
- Android: no version catalog — dependency versions are inlined literals in `app/build.gradle.kts`. Add `androidx.work:work-runtime-ktx:2.10.0`, `androidx.hilt:hilt-work:1.4.0`, `ksp("androidx.hilt:hilt-compiler:1.4.0")` (pins to the existing `hilt-navigation-compose:1.4.0` version already in the project).
- Android: the app has exactly one shared `OkHttpClient`/`Retrofit` (`di/NetworkModule.kt`); a new unauthenticated API call is just another `Api` interface off that same Retrofit instance (same pattern as `ErrorApi`), no second Retrofit instance needed.
- Android: `POST_NOTIFICATIONS` permission is not yet declared (required, targetSdk 36) and must be added to `AndroidManifest.xml`.
- Android: JVM unit tests live flat under `app/src/test/java/dev/scuttle/inventory/<Name>Test.kt`, use `kotlinx.coroutines.test.runTest` + a hand-written `Fake<X>Repository` (no mocking library) + the existing `MainDispatcherRule`.
- Android: reuse the existing `${applicationId}.fileprovider` (`res/xml/file_paths.xml`) for the downloaded APK file, rather than declaring a second provider.

---

## Part A — Laravel backend (`inventory-laravel`)

### Task 1: `inventory_app_releases` migration + model

**Files:**
- Create: `database/migrations/2026_07_24_000000_create_inventory_app_releases_table.php`
- Create: `src/Models/AppRelease.php`
- Test: `tests/Feature/AppReleaseModelTest.php`

**Interfaces:**
- Produces: `Spdotdev\Inventory\Models\AppRelease` — Eloquent model, table `inventory_app_releases`, fillable `['version_code', 'version_name', 'is_breaking', 'min_supported_version_code', 'changelog', 'download_url', 'published_at']`, casts `['is_breaking' => 'boolean', 'published_at' => 'datetime']`. Scope `published()` — `whereNotNull('published_at')`. Static helper `AppRelease::latestPublished(): ?self` — `published()->orderByDesc('version_code')->first()`.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // version_code is the source of truth for "is this newer" — Android's
        // BuildConfig.VERSION_CODE is an int, so ordering/comparison stays a
        // plain integer comparison on both ends. min_supported_version_code is
        // only meaningful when is_breaking is true (validated at the request
        // layer, not the schema layer, per this codebase's existing style of
        // nullable state-dependent fields — see DeleteShelfRequest).
        Schema::create('inventory_app_releases', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version_code')->unique();
            $table->string('version_name');
            $table->boolean('is_breaking')->default(false);
            $table->unsignedInteger('min_supported_version_code')->nullable();
            $table->text('changelog');
            $table->string('download_url');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_app_releases');
    }
};
```

- [ ] **Step 2: Write the model**

```php
<?php

namespace Spdotdev\Inventory\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $version_code
 * @property string $version_name
 * @property bool $is_breaking
 * @property int|null $min_supported_version_code
 * @property string $changelog
 * @property string $download_url
 * @property \Illuminate\Support\Carbon|null $published_at
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
```

- [ ] **Step 3: Write the model test**

```php
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
```

- [ ] **Step 4: Run the migration and test**

Run: `php artisan migrate --path=database/migrations/2026_07_24_000000_create_inventory_app_releases_table.php` (or, inside the test suite's own sqlite setup, simply run the test — `RefreshDatabase` runs all migrations automatically)
Run: `vendor/bin/phpunit --filter AppReleaseModelTest`
Expected: both tests PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_24_000000_create_inventory_app_releases_table.php src/Models/AppRelease.php tests/Feature/AppReleaseModelTest.php
git commit -m "feat: add inventory_app_releases table and AppRelease model"
```

---

### Task 2: Form Requests + API Resource

**Files:**
- Create: `src/Http/Requests/StoreAppReleaseRequest.php`
- Create: `src/Http/Requests/UpdateAppReleaseRequest.php`
- Create: `src/Http/Resources/AppReleaseResource.php`
- Test: `tests/Feature/StoreAppReleaseRequestTest.php`

**Interfaces:**
- Consumes: `Spdotdev\Inventory\Models\AppRelease` (Task 1).
- Produces: `StoreAppReleaseRequest::rules(): array`, `UpdateAppReleaseRequest::rules(): array`, `AppReleaseResource` (wraps one `AppRelease`, `toArray()` returns `id, version_code, version_name, is_breaking, min_supported_version_code, changelog, download_url, published_at` — `published_at` as ISO string or `null`).

- [ ] **Step 1: Write `StoreAppReleaseRequest`**

```php
<?php

namespace Spdotdev\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAppReleaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version_code' => ['required', 'integer', 'min:1', 'unique:inventory_app_releases,version_code'],
            'version_name' => ['required', 'string', 'max:50'],
            'is_breaking' => ['sometimes', 'boolean'],
            'min_supported_version_code' => ['nullable', 'integer', 'min:1'],
            'changelog' => ['required', 'string'],
            'download_url' => ['required', 'url'],
            'publish' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $isBreaking = (bool) $this->input('is_breaking', false);
            $hasMin = $this->filled('min_supported_version_code');

            if ($isBreaking && ! $hasMin) {
                $validator->errors()->add(
                    'min_supported_version_code',
                    'min_supported_version_code is required when is_breaking is true.',
                );
            }

            if (! $isBreaking && $hasMin) {
                $validator->errors()->add(
                    'min_supported_version_code',
                    'min_supported_version_code must be omitted when is_breaking is false.',
                );
            }
        });
    }
}
```

- [ ] **Step 2: Write `UpdateAppReleaseRequest`** (same cross-field rule, every field optional since it's a partial update)

```php
<?php

namespace Spdotdev\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAppReleaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $release = $this->route('appRelease');
        $releaseId = is_object($release) ? $release->id : $release;

        return [
            'version_code' => ['sometimes', 'integer', 'min:1', 'unique:inventory_app_releases,version_code,'.$releaseId],
            'version_name' => ['sometimes', 'string', 'max:50'],
            'is_breaking' => ['sometimes', 'boolean'],
            'min_supported_version_code' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'changelog' => ['sometimes', 'string'],
            'download_url' => ['sometimes', 'url'],
            'publish' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->has('is_breaking') && ! $this->has('min_supported_version_code')) {
                return;
            }

            /** @var \Spdotdev\Inventory\Models\AppRelease $existing */
            $existing = $this->route('appRelease');
            $isBreaking = $this->boolean('is_breaking', $existing->is_breaking);
            $hasMin = $this->has('min_supported_version_code')
                ? $this->filled('min_supported_version_code')
                : $existing->min_supported_version_code !== null;

            if ($isBreaking && ! $hasMin) {
                $validator->errors()->add(
                    'min_supported_version_code',
                    'min_supported_version_code is required when is_breaking is true.',
                );
            }
        });
    }
}
```

- [ ] **Step 3: Write `AppReleaseResource`**

```php
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
```

- [ ] **Step 4: Write the validation test**

```php
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
```

- [ ] **Step 5: Run the test** (this will fail until Task 3's controller/routes exist — that's expected)

Run: `vendor/bin/phpunit --filter StoreAppReleaseRequestTest`
Expected: FAIL with a 404 (route not found) — confirms the test file itself is wired correctly; it will pass once Task 3 registers the route.

- [ ] **Step 6: Commit**

```bash
git add src/Http/Requests/StoreAppReleaseRequest.php src/Http/Requests/UpdateAppReleaseRequest.php src/Http/Resources/AppReleaseResource.php tests/Feature/StoreAppReleaseRequestTest.php
git commit -m "feat: add app release form requests and API resource"
```

---

### Task 3: `AppReleaseController` + routes (public read + admin writes)

**Files:**
- Create: `src/Http/Controllers/Api/AppReleaseController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/AppReleaseApiTest.php`

**Interfaces:**
- Consumes: `AppRelease` (Task 1), `StoreAppReleaseRequest`, `UpdateAppReleaseRequest`, `AppReleaseResource` (Task 2).
- Produces: `GET /api/v1/app-version` (public), `GET /api/v1/admin/app-releases` (list, admin), `POST /api/v1/admin/app-releases` (create, admin), `PATCH /api/v1/admin/app-releases/{appRelease}` (update, admin).

- [ ] **Step 1: Write the controller**

```php
<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Spdotdev\Inventory\Http\Requests\StoreAppReleaseRequest;
use Spdotdev\Inventory\Http\Requests\UpdateAppReleaseRequest;
use Spdotdev\Inventory\Http\Resources\AppReleaseResource;
use Spdotdev\Inventory\Models\AppRelease;

class AppReleaseController
{
    public function latest(): JsonResponse
    {
        $release = AppRelease::latestPublished();

        return response()->json(['data' => $release ? new AppReleaseResource($release) : null]);
    }

    public function index(): JsonResponse
    {
        $releases = AppRelease::orderByDesc('version_code')->get();

        return response()->json(['data' => AppReleaseResource::collection($releases)]);
    }

    public function store(StoreAppReleaseRequest $request): JsonResponse
    {
        $data = $request->validated();
        $publish = (bool) ($data['publish'] ?? false);
        unset($data['publish']);
        $data['published_at'] = $publish ? now() : null;

        $release = AppRelease::create($data);

        return response()->json(['data' => new AppReleaseResource($release)], 201);
    }

    public function update(UpdateAppReleaseRequest $request, AppRelease $appRelease): JsonResponse
    {
        $data = $request->validated();
        if (array_key_exists('publish', $data)) {
            $data['published_at'] = $data['publish'] ? now() : null;
            unset($data['publish']);
        }

        $appRelease->update($data);

        return response()->json(['data' => new AppReleaseResource($appRelease->fresh())]);
    }
}
```

- [ ] **Step 2: Register routes** — add the public route to the outer `api/v1` group (alongside `/health`) and the admin routes to the existing `admin` group in `routes/api.php`

```php
// Inside the outer prefix('api/v1') group, near HealthController:
Route::get('/app-version', [AppReleaseController::class, 'latest'])->name('inventory.api.app-version');

// Inside the existing Route::middleware(['inventory.admin', 'throttle:inventory-admin'])->prefix('admin')->group(...) block, alongside the households/users routes:
Route::get('app-releases', [AppReleaseController::class, 'index']);
Route::post('app-releases', [AppReleaseController::class, 'store']);
Route::patch('app-releases/{appRelease}', [AppReleaseController::class, 'update']);
```

Add `use Spdotdev\Inventory\Http\Controllers\Api\AppReleaseController;` to the top of `routes/api.php` alongside the other controller `use` statements.

- [ ] **Step 3: Run the Task 2 test — it should now pass**

Run: `vendor/bin/phpunit --filter StoreAppReleaseRequestTest`
Expected: PASS (all 3 tests).

- [ ] **Step 4: Write `AppReleaseApiTest`**

```php
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
```

- [ ] **Step 5: Run the full test file**

Run: `vendor/bin/phpunit --filter AppReleaseApiTest`
Expected: PASS (6 tests).

- [ ] **Step 6: Run the whole suite to check for regressions**

Run: `vendor/bin/phpunit`
Expected: PASS, no regressions in `AdminApiTest` or elsewhere.

- [ ] **Step 7: Commit**

```bash
git add src/Http/Controllers/Api/AppReleaseController.php routes/api.php tests/Feature/AppReleaseApiTest.php
git commit -m "feat: add public app-version endpoint and admin app-releases CRUD"
```

---

## Part B — MCP tools (`inventory-mcp`)

### Task 4: `create_app_release`, `list_app_releases`, `update_app_release` tools

**Files:**
- Modify: `src/server.ts`
- Modify: `test/server.test.mjs`

**Interfaces:**
- Consumes: the Task 3 admin endpoints (`GET/POST /admin/app-releases`, `PATCH /admin/app-releases/{id}`) via the existing `adminFetch()` helper.
- Produces: three new MCP tools discoverable by name in `EXPECTED_TOOLS`.

- [ ] **Step 1: Add the three tools to `src/server.ts`**, in a new `// ─── App Releases ───` section, following the exact `create_household`/`list_users`/`get_household` shapes already in the file:

```ts
// ─── App Releases ───

server.registerTool(
  "list_app_releases",
  {
    description: "List all app releases, including unpublished drafts, newest version_code first.",
    annotations: { readOnlyHint: true },
  },
  async () => asText(await adminFetch("/app-releases")),
);

if (!readOnly) {
  server.registerTool(
    "create_app_release",
    {
      description:
        "Create a new Android app release entry. Set is_breaking + min_supported_version_code " +
        "for a release that requires users below that version to update before continuing. " +
        "Set publish=true to make it immediately visible to the app's update check, or leave " +
        "it false to create a draft reviewable via list_app_releases first.",
      inputSchema: {
        version_code: z.number().int().positive().describe("Matches Android's versionCode"),
        version_name: z.string().max(50).describe("Matches Android's versionName, e.g. 0.1.22"),
        is_breaking: z.boolean().optional().describe("True if this release requires a mandatory update"),
        min_supported_version_code: z
          .number()
          .int()
          .positive()
          .optional()
          .describe("Required when is_breaking is true; installs below this are hard-blocked"),
        changelog: z.string().describe("Shown in the update dialog and (truncated) the notification"),
        download_url: z.string().url().describe("GitHub prerelease APK asset URL"),
        publish: z.boolean().optional().describe("Publish immediately instead of creating a draft"),
      },
    },
    async (params) =>
      asText(
        await adminFetch("/app-releases", {
          method: "POST",
          body: JSON.stringify(params),
        }),
      ),
  );

  server.registerTool(
    "update_app_release",
    {
      description:
        "Update an existing app release, including publishing a draft (publish=true) after review.",
      inputSchema: {
        id: z.number().int().positive().describe("App release ID (from list_app_releases)"),
        version_code: z.number().int().positive().optional(),
        version_name: z.string().max(50).optional(),
        is_breaking: z.boolean().optional(),
        min_supported_version_code: z.number().int().positive().nullable().optional(),
        changelog: z.string().optional(),
        download_url: z.string().url().optional(),
        publish: z.boolean().optional(),
      },
    },
    async ({ id, ...body }) =>
      asText(
        await adminFetch(`/app-releases/${id}`, {
          method: "PATCH",
          body: JSON.stringify(body),
        }),
      ),
  );
}
```

- [ ] **Step 2: Update `EXPECTED_TOOLS` in `test/server.test.mjs`** to include the three new names (find the existing list/assertion — e.g. `"exposes exactly the seven admin tools"` — and change the count/name to match; the existing test name and count both need updating, e.g. to "exposes exactly the ten admin tools" and expected size 10, keeping alphabetic/section grouping consistent with how the file already lists tool names).

- [ ] **Step 3: Add tests for the new tools** to `test/server.test.mjs`, following the existing `connectedClient`/`stubFetch` pattern:

```js
test("create_app_release posts to /admin/app-releases", async () => {
  const fetchStub = stubFetch({ body: { data: { id: 1, version_code: 22 } } });
  const client = await connectedClient(fetchStub);

  const result = await client.callTool({
    name: "create_app_release",
    arguments: {
      version_code: 22,
      version_name: "0.1.21",
      changelog: "test release",
      download_url: "https://example.test/app.apk",
    },
  });

  assert.equal(fetchStub.calls.length, 1);
  assert.equal(fetchStub.calls[0].url, `${BASE_URL}/admin/app-releases`);
  assert.equal(fetchStub.calls[0].options.method, "POST");
  assert.match(result.content[0].text, /"version_code": 22/);
});

test("create_app_release is unavailable in read-only mode", async () => {
  const fetchStub = stubFetch();
  const client = await connectedClient(fetchStub, { readOnly: true });

  const tools = await client.listTools();
  assert.ok(!tools.tools.some((t) => t.name === "create_app_release"));
});

test("list_app_releases fetches /admin/app-releases", async () => {
  const fetchStub = stubFetch({ body: { data: [] } });
  const client = await connectedClient(fetchStub);

  await client.callTool({ name: "list_app_releases", arguments: {} });

  assert.equal(fetchStub.calls[0].url, `${BASE_URL}/admin/app-releases`);
  assert.equal(fetchStub.calls[0].options.method ?? "GET", "GET");
});

test("update_app_release patches /admin/app-releases/{id}", async () => {
  const fetchStub = stubFetch({ body: { data: { id: 5, published_at: "2026-07-24T00:00:00Z" } } });
  const client = await connectedClient(fetchStub);

  await client.callTool({
    name: "update_app_release",
    arguments: { id: 5, publish: true },
  });

  assert.equal(fetchStub.calls[0].url, `${BASE_URL}/admin/app-releases/5`);
  assert.equal(fetchStub.calls[0].options.method, "PATCH");
});
```

- [ ] **Step 4: Build and run the test suite**

Run: `npm test`
Expected: PASS, including the updated `EXPECTED_TOOLS` count/name assertion and all 4 new tests.

- [ ] **Step 5: Commit**

```bash
git add src/server.ts test/server.test.mjs
git commit -m "feat: add create/list/update_app_release MCP tools"
```

---

## Part C — Android client (`inventory-android`)

### Task 5: Add WorkManager + Hilt-Work dependencies

**Files:**
- Modify: `app/build.gradle.kts`

**Interfaces:**
- Produces: `androidx.work.CoroutineWorker`, `androidx.hilt.work.HiltWorker` annotation, and `androidx.hilt:hilt-compiler` KSP processing become available to later tasks.

- [ ] **Step 1: Add the dependencies** to the existing `dependencies { }` block in `app/build.gradle.kts`, alongside the other `implementation`/`ksp` lines:

```kotlin
implementation("androidx.work:work-runtime-ktx:2.10.0")
implementation("androidx.hilt:hilt-work:1.4.0")
ksp("androidx.hilt:hilt-compiler:1.4.0")
```

- [ ] **Step 2: Sync and build to confirm no dependency conflicts**

Run: `./gradlew :app:assembleDebug`
Expected: BUILD SUCCESSFUL (no version resolution errors).

- [ ] **Step 3: Commit**

```bash
git add app/build.gradle.kts
git commit -m "build: add WorkManager and Hilt-Work dependencies"
```

---

### Task 6: `AppReleaseApi`, DTOs, `AppUpdateRepository`, `VersionComparator`

**Files:**
- Create: `app/src/main/java/dev/scuttle/inventory/data/dto/AppReleaseDto.kt`
- Create: `app/src/main/java/dev/scuttle/inventory/data/api/AppReleaseApi.kt`
- Create: `app/src/main/java/dev/scuttle/inventory/data/appupdate/UpdateStatus.kt`
- Create: `app/src/main/java/dev/scuttle/inventory/data/appupdate/VersionComparator.kt`
- Create: `app/src/main/java/dev/scuttle/inventory/data/appupdate/AppUpdateRepository.kt`
- Create: `app/src/main/java/dev/scuttle/inventory/data/appupdate/AppUpdateRepositoryImpl.kt`
- Modify: `app/src/main/java/dev/scuttle/inventory/di/NetworkModule.kt`
- Test: `app/src/test/java/dev/scuttle/inventory/VersionComparatorTest.kt`
- Test: `app/src/test/java/dev/scuttle/inventory/AppUpdateRepositoryTest.kt`

**Interfaces:**
- Produces: `AppReleaseDto(id: Int, versionCode: Int, versionName: String, isBreaking: Boolean, minSupportedVersionCode: Int?, changelog: String, downloadUrl: String, publishedAt: String?)`; `AppReleaseApi.latest(): AppReleaseResponse` where `AppReleaseResponse(data: AppReleaseDto?)`; `sealed interface UpdateStatus { object None; data class Optional(release: AppReleaseDto); data class Breaking(release: AppReleaseDto) }`; `VersionComparator.classify(installedVersionCode: Int, release: AppReleaseDto?): UpdateStatus`; `AppUpdateRepository.check(): UpdateStatus` (never throws — catches and returns `UpdateStatus.None` on failure).

- [ ] **Step 1: Write the DTOs**

```kotlin
package dev.scuttle.inventory.data.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class AppReleaseDto(
    val id: Int,
    @SerialName("version_code") val versionCode: Int,
    @SerialName("version_name") val versionName: String,
    @SerialName("is_breaking") val isBreaking: Boolean = false,
    @SerialName("min_supported_version_code") val minSupportedVersionCode: Int? = null,
    val changelog: String,
    @SerialName("download_url") val downloadUrl: String,
    @SerialName("published_at") val publishedAt: String? = null,
)

@Serializable
data class AppReleaseResponse(
    val data: AppReleaseDto? = null,
)
```

- [ ] **Step 2: Write `AppReleaseApi`**

```kotlin
package dev.scuttle.inventory.data.api

import dev.scuttle.inventory.data.dto.AppReleaseResponse
import retrofit2.http.GET

interface AppReleaseApi {
    @GET("app-version")
    suspend fun latest(): AppReleaseResponse
}
```

- [ ] **Step 3: Add the provider to `NetworkModule.kt`**, alongside the other `provide*Api` functions:

```kotlin
@Provides
@Singleton
fun provideAppReleaseApi(retrofit: Retrofit): AppReleaseApi = retrofit.create(AppReleaseApi::class.java)
```

(Add `import dev.scuttle.inventory.data.api.AppReleaseApi` to the file's imports.)

- [ ] **Step 4: Write `UpdateStatus`**

```kotlin
package dev.scuttle.inventory.data.appupdate

import dev.scuttle.inventory.data.dto.AppReleaseDto

sealed interface UpdateStatus {
    data object None : UpdateStatus

    data class Optional(val release: AppReleaseDto) : UpdateStatus

    data class Breaking(val release: AppReleaseDto) : UpdateStatus
}
```

- [ ] **Step 5: Write `VersionComparator`**

```kotlin
package dev.scuttle.inventory.data.appupdate

import dev.scuttle.inventory.data.dto.AppReleaseDto

object VersionComparator {
    fun classify(
        installedVersionCode: Int,
        release: AppReleaseDto?,
    ): UpdateStatus {
        if (release == null || release.versionCode <= installedVersionCode) {
            return UpdateStatus.None
        }

        val minSupported = release.minSupportedVersionCode
        val isHardBlocked = release.isBreaking && minSupported != null && installedVersionCode < minSupported

        return if (isHardBlocked) {
            UpdateStatus.Breaking(release)
        } else {
            UpdateStatus.Optional(release)
        }
    }
}
```

- [ ] **Step 6: Write the `VersionComparatorTest`**

```kotlin
package dev.scuttle.inventory

import dev.scuttle.inventory.data.appupdate.UpdateStatus
import dev.scuttle.inventory.data.appupdate.VersionComparator
import dev.scuttle.inventory.data.dto.AppReleaseDto
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class VersionComparatorTest {
    private fun release(
        versionCode: Int,
        isBreaking: Boolean = false,
        minSupportedVersionCode: Int? = null,
    ) = AppReleaseDto(
        id = 1,
        versionCode = versionCode,
        versionName = "0.1.$versionCode",
        isBreaking = isBreaking,
        minSupportedVersionCode = minSupportedVersionCode,
        changelog = "test",
        downloadUrl = "https://example.test/app.apk",
    )

    @Test
    fun no_release_is_none() {
        assertEquals(UpdateStatus.None, VersionComparator.classify(21, null))
    }

    @Test
    fun release_at_or_below_installed_is_none() {
        assertEquals(UpdateStatus.None, VersionComparator.classify(21, release(versionCode = 21)))
        assertEquals(UpdateStatus.None, VersionComparator.classify(21, release(versionCode = 20)))
    }

    @Test
    fun newer_non_breaking_release_is_optional() {
        val result = VersionComparator.classify(21, release(versionCode = 22, isBreaking = false))
        assertTrue(result is UpdateStatus.Optional)
    }

    @Test
    fun newer_breaking_release_with_installed_equal_to_min_is_optional_not_breaking() {
        val result =
            VersionComparator.classify(
                installedVersionCode = 20,
                release = release(versionCode = 22, isBreaking = true, minSupportedVersionCode = 20),
            )
        assertTrue(result is UpdateStatus.Optional)
    }

    @Test
    fun newer_breaking_release_with_installed_below_min_is_breaking() {
        val result =
            VersionComparator.classify(
                installedVersionCode = 19,
                release = release(versionCode = 22, isBreaking = true, minSupportedVersionCode = 20),
            )
        assertTrue(result is UpdateStatus.Breaking)
    }
}
```

- [ ] **Step 7: Run the comparator test to verify it passes**

Run: `./gradlew :app:testDebugUnitTest --tests "dev.scuttle.inventory.VersionComparatorTest"`
Expected: PASS (5 tests).

- [ ] **Step 8: Write `AppUpdateRepository` interface + impl**

```kotlin
package dev.scuttle.inventory.data.appupdate

interface AppUpdateRepository {
    suspend fun check(): UpdateStatus
}
```

```kotlin
package dev.scuttle.inventory.data.appupdate

import android.util.Log
import dev.scuttle.inventory.BuildConfig
import dev.scuttle.inventory.data.api.AppReleaseApi
import javax.inject.Inject

class AppUpdateRepositoryImpl
    @Inject
    constructor(
        private val api: AppReleaseApi,
    ) : AppUpdateRepository {
        override suspend fun check(): UpdateStatus =
            try {
                val release = api.latest().data
                VersionComparator.classify(BuildConfig.VERSION_CODE, release)
            } catch (e: Exception) {
                Log.w("AppUpdateRepository", "Update check failed, treating as no update", e)
                UpdateStatus.None
            }
    }
```

- [ ] **Step 9: Write `AppUpdateRepositoryTest`**

```kotlin
package dev.scuttle.inventory

import dev.scuttle.inventory.data.api.AppReleaseApi
import dev.scuttle.inventory.data.appupdate.AppUpdateRepositoryImpl
import dev.scuttle.inventory.data.appupdate.UpdateStatus
import dev.scuttle.inventory.data.dto.AppReleaseDto
import dev.scuttle.inventory.data.dto.AppReleaseResponse
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

class AppUpdateRepositoryTest {
    @get:Rule
    val mainDispatcherRule = MainDispatcherRule()

    private class FakeAppReleaseApi(
        private val response: AppReleaseResponse? = null,
        private val throwOnCall: Boolean = false,
    ) : AppReleaseApi {
        override suspend fun latest(): AppReleaseResponse {
            if (throwOnCall) throw RuntimeException("offline")
            return response ?: AppReleaseResponse(data = null)
        }
    }

    @Test
    fun check_returns_none_when_no_release_exists() =
        runTest {
            val repository = AppUpdateRepositoryImpl(FakeAppReleaseApi())

            assertEquals(UpdateStatus.None, repository.check())
        }

    @Test
    fun check_returns_optional_for_a_newer_non_breaking_release() =
        runTest {
            val dto =
                AppReleaseDto(
                    id = 1,
                    versionCode = BuildConfig.VERSION_CODE + 1,
                    versionName = "future",
                    changelog = "new stuff",
                    downloadUrl = "https://example.test/app.apk",
                )
            val repository = AppUpdateRepositoryImpl(FakeAppReleaseApi(AppReleaseResponse(data = dto)))

            assertTrue(repository.check() is UpdateStatus.Optional)
        }

    @Test
    fun check_returns_none_when_the_api_call_fails() =
        runTest {
            val repository = AppUpdateRepositoryImpl(FakeAppReleaseApi(throwOnCall = true))

            assertEquals(UpdateStatus.None, repository.check())
        }
}
```

- [ ] **Step 10: Add the Hilt binding** for `AppUpdateRepository` — find the existing `@Binds` module for repositories (e.g. wherever `InviteRepositoryImpl` is bound to `InviteRepository`) and add an equivalent `@Binds abstract fun bindAppUpdateRepository(impl: AppUpdateRepositoryImpl): AppUpdateRepository` in the same `@Module` `abstract class`.

- [ ] **Step 11: Run the full JVM unit test suite**

Run: `./gradlew :app:testDebugUnitTest`
Expected: PASS, no regressions.

- [ ] **Step 12: Commit**

```bash
git add app/src/main/java/dev/scuttle/inventory/data/dto/AppReleaseDto.kt \
        app/src/main/java/dev/scuttle/inventory/data/api/AppReleaseApi.kt \
        app/src/main/java/dev/scuttle/inventory/data/appupdate/ \
        app/src/main/java/dev/scuttle/inventory/di/NetworkModule.kt \
        app/src/test/java/dev/scuttle/inventory/VersionComparatorTest.kt \
        app/src/test/java/dev/scuttle/inventory/AppUpdateRepositoryTest.kt
git commit -m "feat: add AppUpdateRepository and version-classification logic"
```

---

### Task 7: Notification channel + `POST_NOTIFICATIONS` permission + WorkManager scheduling

**Files:**
- Modify: `app/src/main/AndroidManifest.xml`
- Modify: `app/src/main/java/dev/scuttle/inventory/InventoryApp.kt`
- Create: `app/src/main/java/dev/scuttle/inventory/work/AppUpdateCheckWorker.kt`
- Create: `app/src/main/java/dev/scuttle/inventory/work/AppUpdateNotifier.kt`

**Interfaces:**
- Consumes: `AppUpdateRepository` (Task 6).
- Produces: notification channel id `"app_updates"`; `AppUpdateCheckWorker` (a `@HiltWorker`) scheduled every 24h under work name `"app_update_check"`.

- [ ] **Step 1: Add the permission to `AndroidManifest.xml`**, alongside the existing `<uses-permission>` entries:

```xml
<uses-permission android:name="android.permission.POST_NOTIFICATIONS" />
```

- [ ] **Step 2: Disable WorkManager's default initializer** so Hilt's `Configuration.Provider` takes over — add inside `<application>`, right after the opening tag, before the `<activity>` entry:

```xml
<provider
    android:name="androidx.startup.InitializationProvider"
    android:authorities="${applicationId}.androidx-startup"
    android:exported="false"
    tools:node="merge">
    <meta-data
        android:name="androidx.work.WorkManagerInitializer"
        android:value="androidx.startup"
        tools:node="remove" />
</provider>
```

Add the `xmlns:tools="http://schemas.android.com/tools"` namespace to the root `<manifest>` tag if not already present.

- [ ] **Step 3: Write `AppUpdateNotifier`** (channel creation + posting, isolated so it's easy to call from both the worker and later the dialog if ever needed)

```kotlin
package dev.scuttle.inventory.work

import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.content.pm.PackageManager
import androidx.core.app.ActivityCompat
import androidx.core.app.NotificationCompat
import dev.scuttle.inventory.MainActivity
import dev.scuttle.inventory.R
import dev.scuttle.inventory.data.appupdate.UpdateStatus
import android.app.PendingIntent
import android.content.Intent

private const val CHANNEL_ID = "app_updates"
private const val NOTIFICATION_ID = 1001

fun createAppUpdatesNotificationChannel(context: Context) {
    val channel =
        NotificationChannel(
            CHANNEL_ID,
            context.getString(R.string.notification_channel_app_updates_name),
            NotificationManager.IMPORTANCE_DEFAULT,
        ).apply {
            description = context.getString(R.string.notification_channel_app_updates_description)
        }
    val manager = context.getSystemService(NotificationManager::class.java)
    manager.createNotificationChannel(channel)
}

fun postAppUpdateNotification(
    context: Context,
    status: UpdateStatus,
) {
    val release =
        when (status) {
            is UpdateStatus.Optional -> status.release
            is UpdateStatus.Breaking -> status.release
            UpdateStatus.None -> return
        }
    val isBreaking = status is UpdateStatus.Breaking
    val title =
        context.getString(
            if (isBreaking) {
                R.string.notification_app_update_required_title
            } else {
                R.string.notification_app_update_available_title
            },
        )
    val body = release.changelog.lineSequence().first().take(100)

    val intent = Intent(context, MainActivity::class.java).apply {
        flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
    }
    val pendingIntent =
        PendingIntent.getActivity(
            context,
            0,
            intent,
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )

    val notification =
        NotificationCompat.Builder(context, CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(title)
            .setContentText(body)
            .setAutoCancel(true)
            .setContentIntent(pendingIntent)
            .build()

    if (ActivityCompat.checkSelfPermission(
            context,
            android.Manifest.permission.POST_NOTIFICATIONS,
        ) != PackageManager.PERMISSION_GRANTED
    ) {
        return
    }
    context.getSystemService(NotificationManager::class.java).notify(NOTIFICATION_ID, notification)
}
```

Add the two string resources (`notification_channel_app_updates_name`, `notification_channel_app_updates_description`, `notification_app_update_required_title`, `notification_app_update_available_title`) to `app/src/main/res/values/strings.xml` (and the `values-nl` equivalent, matching this app's EN+NL localization convention) — e.g.:

```xml
<string name="notification_channel_app_updates_name">App updates</string>
<string name="notification_channel_app_updates_description">Notifies you when a new app version is available</string>
<string name="notification_app_update_required_title">Update required</string>
<string name="notification_app_update_available_title">Update available</string>
```

If `@drawable/ic_notification` doesn't already exist, add a simple vector drawable (single-color, per Android's notification-icon requirements) at `app/src/main/res/drawable/ic_notification.xml` — reuse any existing simple monochrome icon in the project's drawable set as a template if one exists, otherwise a minimal vector circle/glyph.

- [ ] **Step 4: Write `AppUpdateCheckWorker`**

```kotlin
package dev.scuttle.inventory.work

import android.content.Context
import androidx.hilt.work.HiltWorker
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import dagger.assisted.Assisted
import dagger.assisted.AssistedInject
import dev.scuttle.inventory.data.appupdate.AppUpdateRepository

@HiltWorker
class AppUpdateCheckWorker
    @AssistedInject
    constructor(
        @Assisted appContext: Context,
        @Assisted workerParams: WorkerParameters,
        private val repository: AppUpdateRepository,
    ) : CoroutineWorker(appContext, workerParams) {
        override suspend fun doWork(): Result {
            val status = repository.check()
            postAppUpdateNotification(applicationContext, status)
            return Result.success()
        }
    }
```

- [ ] **Step 5: Wire up `InventoryApp.kt`** — implement `Configuration.Provider` for Hilt-WorkManager, create the notification channel, and schedule the periodic worker:

```kotlin
package dev.scuttle.inventory

import android.app.Application
import androidx.hilt.work.HiltWorkerFactory
import androidx.work.Configuration
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import dagger.hilt.android.HiltAndroidApp
import dev.scuttle.inventory.work.AppUpdateCheckWorker
import dev.scuttle.inventory.work.createAppUpdatesNotificationChannel
import java.util.concurrent.TimeUnit
import javax.inject.Inject

@HiltAndroidApp
class InventoryApp : Application(), Configuration.Provider {
    @Inject
    lateinit var workerFactory: HiltWorkerFactory

    override val workManagerConfiguration: Configuration
        get() = Configuration.Builder().setWorkerFactory(workerFactory).build()

    override fun onCreate() {
        super.onCreate()
        createAppUpdatesNotificationChannel(this)
        scheduleAppUpdateCheck()
    }

    private fun scheduleAppUpdateCheck() {
        val request =
            PeriodicWorkRequestBuilder<AppUpdateCheckWorker>(24, TimeUnit.HOURS)
                .build()
        WorkManager.getInstance(this)
            .enqueueUniquePeriodicWork(
                "app_update_check",
                ExistingPeriodicWorkPolicy.KEEP,
                request,
            )
    }
}
```

- [ ] **Step 6: Build the app to verify Hilt annotation processing succeeds**

Run: `./gradlew :app:assembleDebug`
Expected: BUILD SUCCESSFUL (confirms `@HiltWorker`/`@AssistedInject` generated code compiles and `Configuration.Provider` wiring is valid).

- [ ] **Step 7: Commit**

```bash
git add app/src/main/AndroidManifest.xml app/src/main/java/dev/scuttle/inventory/InventoryApp.kt \
        app/src/main/java/dev/scuttle/inventory/work/ app/src/main/res/values/strings.xml \
        app/src/main/res/values-nl/strings.xml app/src/main/res/drawable/ic_notification.xml
git commit -m "feat: schedule periodic background update check with local notification"
```

---

### Task 8: `UpdateDialog` + app-open check + APK download/install

**Files:**
- Create: `app/src/main/java/dev/scuttle/inventory/ui/appupdate/AppUpdateViewModel.kt`
- Create: `app/src/main/java/dev/scuttle/inventory/ui/appupdate/UpdateDialog.kt`
- Create: `app/src/main/java/dev/scuttle/inventory/ui/appupdate/UpdateInstaller.kt`
- Modify: `app/src/main/java/dev/scuttle/inventory/MainActivity.kt`
- Modify: `app/src/main/AndroidManifest.xml`
- Modify: `app/src/main/res/xml/file_paths.xml`
- Test: `app/src/test/java/dev/scuttle/inventory/AppUpdateViewModelTest.kt`

**Interfaces:**
- Consumes: `AppUpdateRepository.check()` (Task 6).
- Produces: `AppUpdateViewModel.status: StateFlow<UpdateStatus>`, `AppUpdateViewModel.refresh()`; `UpdateDialog(status: UpdateStatus, onUpdateClick: () -> Unit, onDismiss: () -> Unit)` composable; `UpdateInstaller.downloadAndInstall(context: Context, downloadUrl: String)`.

- [ ] **Step 1: Write `AppUpdateViewModel`**

```kotlin
package dev.scuttle.inventory.ui.appupdate

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import dev.scuttle.inventory.data.appupdate.AppUpdateRepository
import dev.scuttle.inventory.data.appupdate.UpdateStatus
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import javax.inject.Inject

@HiltViewModel
class AppUpdateViewModel
    @Inject
    constructor(
        private val repository: AppUpdateRepository,
    ) : ViewModel() {
        private val _status = MutableStateFlow<UpdateStatus>(UpdateStatus.None)
        val status: StateFlow<UpdateStatus> = _status.asStateFlow()

        private var dismissedOptional = false

        fun refresh() {
            viewModelScope.launch {
                _status.value = repository.check()
            }
        }

        fun dismissOptional() {
            dismissedOptional = true
        }

        val isDialogVisible: Boolean
            get() =
                when (val current = status.value) {
                    UpdateStatus.None -> false
                    is UpdateStatus.Optional -> !dismissedOptional
                    is UpdateStatus.Breaking -> true
                }
    }
```

- [ ] **Step 2: Write `AppUpdateViewModelTest`**

```kotlin
package dev.scuttle.inventory

import dev.scuttle.inventory.data.appupdate.AppUpdateRepository
import dev.scuttle.inventory.data.appupdate.UpdateStatus
import dev.scuttle.inventory.data.dto.AppReleaseDto
import dev.scuttle.inventory.ui.appupdate.AppUpdateViewModel
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

class AppUpdateViewModelTest {
    @get:Rule
    val mainDispatcherRule = MainDispatcherRule()

    private val release =
        AppReleaseDto(
            id = 1,
            versionCode = 999,
            versionName = "future",
            changelog = "new stuff",
            downloadUrl = "https://example.test/app.apk",
        )

    private class FakeAppUpdateRepository(
        private val result: UpdateStatus,
    ) : AppUpdateRepository {
        override suspend fun check(): UpdateStatus = result
    }

    @Test
    fun refresh_populates_status() =
        runTest {
            val viewModel = AppUpdateViewModel(FakeAppUpdateRepository(UpdateStatus.Optional(release)))

            viewModel.refresh()

            assertEquals(UpdateStatus.Optional(release), viewModel.status.value)
        }

    @Test
    fun optional_dialog_can_be_dismissed() =
        runTest {
            val viewModel = AppUpdateViewModel(FakeAppUpdateRepository(UpdateStatus.Optional(release)))
            viewModel.refresh()

            assertTrue(viewModel.isDialogVisible)
            viewModel.dismissOptional()
            assertFalse(viewModel.isDialogVisible)
        }

    @Test
    fun breaking_dialog_ignores_dismiss() =
        runTest {
            val viewModel = AppUpdateViewModel(FakeAppUpdateRepository(UpdateStatus.Breaking(release)))
            viewModel.refresh()

            viewModel.dismissOptional()

            assertTrue(viewModel.isDialogVisible)
        }
}
```

- [ ] **Step 3: Run the ViewModel test**

Run: `./gradlew :app:testDebugUnitTest --tests "dev.scuttle.inventory.AppUpdateViewModelTest"`
Expected: PASS (3 tests).

- [ ] **Step 4: Write `UpdateInstaller`**

```kotlin
package dev.scuttle.inventory.ui.appupdate

import android.content.Context
import android.content.Intent
import androidx.core.content.FileProvider
import java.io.File
import java.net.URL
import javax.inject.Inject

class UpdateInstaller
    @Inject
    constructor() {
        suspend fun downloadAndInstall(
            context: Context,
            downloadUrl: String,
        ) {
            val apkDir = File(context.cacheDir, "apk").apply { mkdirs() }
            val apkFile = File(apkDir, "update.apk")

            URL(downloadUrl).openStream().use { input ->
                apkFile.outputStream().use { output -> input.copyTo(output) }
            }

            val uri =
                FileProvider.getUriForFile(
                    context,
                    "${context.packageName}.fileprovider",
                    apkFile,
                )
            val intent =
                Intent(Intent.ACTION_VIEW).apply {
                    setDataAndType(uri, "application/vnd.android.package-archive")
                    addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                    addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                }
            context.startActivity(intent)
        }
    }
```

The download runs on a background dispatcher — call it from `viewModelScope.launch(Dispatchers.IO) { ... }` at the call site in Step 6 below.

- [ ] **Step 5: Add the `apk` cache path and install permission** — modify `app/src/main/res/xml/file_paths.xml`:

```xml
<?xml version="1.0" encoding="utf-8"?>
<paths>
    <cache-path name="camera_images" path="camera/" />
    <cache-path name="exports" path="exports/" />
    <cache-path name="apk" path="apk/" />
</paths>
```

Add to `AndroidManifest.xml`, alongside the other `<uses-permission>` entries:

```xml
<uses-permission android:name="android.permission.REQUEST_INSTALL_PACKAGES" />
```

- [ ] **Step 6: Write `UpdateDialog`**

```kotlin
package dev.scuttle.inventory.ui.appupdate

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.window.DialogProperties
import dev.scuttle.inventory.R
import dev.scuttle.inventory.data.appupdate.UpdateStatus

@Composable
fun UpdateDialog(
    status: UpdateStatus,
    onUpdateClick: () -> Unit,
    onDismiss: () -> Unit,
) {
    val release =
        when (status) {
            is UpdateStatus.Optional -> status.release
            is UpdateStatus.Breaking -> status.release
            UpdateStatus.None -> return
        }
    val isBreaking = status is UpdateStatus.Breaking

    AlertDialog(
        onDismissRequest = { if (!isBreaking) onDismiss() },
        properties =
            DialogProperties(
                dismissOnBackPress = !isBreaking,
                dismissOnClickOutside = !isBreaking,
            ),
        title = {
            Text(
                stringResource(
                    id =
                        if (isBreaking) {
                            R.string.update_dialog_required_title
                        } else {
                            R.string.update_dialog_available_title
                        },
                ),
            )
        },
        text = {
            Column(modifier = Modifier.heightIn(max = 300.dp).verticalScroll(rememberScrollState())) {
                Text(release.versionName)
                Text(release.changelog)
            }
        },
        confirmButton = {
            TextButton(onClick = onUpdateClick) {
                Text(stringResource(id = R.string.update_dialog_update_now))
            }
        },
        dismissButton =
            if (!isBreaking) {
                {
                    TextButton(onClick = onDismiss) {
                        Text(stringResource(id = R.string.update_dialog_later))
                    }
                }
            } else {
                null
            },
    )
}
```

(Add `import androidx.compose.ui.res.stringResource` and `import androidx.compose.ui.unit.dp`.) Add the four string resources to `strings.xml`/`values-nl/strings.xml`:

```xml
<string name="update_dialog_required_title">Update required</string>
<string name="update_dialog_available_title">Update available</string>
<string name="update_dialog_update_now">Update now</string>
<string name="update_dialog_later">Later</string>
```

- [ ] **Step 7: Wire the dialog into `MainActivity.kt`** — modify the `setContent { }` block so the update check runs once per process start and the dialog renders above `InventoryNavHost`:

```kotlin
setContent {
    val themeViewModel: ThemeViewModel = hiltViewModel()
    val mode by themeViewModel.mode.collectAsState()
    val dark = when (mode) {
        ThemeMode.SYSTEM -> isSystemInDarkTheme()
        ThemeMode.LIGHT -> false
        ThemeMode.DARK -> true
    }
    val appUpdateViewModel: AppUpdateViewModel = hiltViewModel()
    val updateStatus by appUpdateViewModel.status.collectAsState()
    val context = LocalContext.current
    val coroutineScope = rememberCoroutineScope()
    val updateInstaller: UpdateInstaller = remember { UpdateInstaller() }

    LaunchedEffect(Unit) { appUpdateViewModel.refresh() }

    InventoryTheme(darkTheme = dark) {
        Surface(modifier = Modifier.fillMaxSize()) {
            InventoryNavHost(themeViewModel = themeViewModel)
            if (appUpdateViewModel.isDialogVisible) {
                UpdateDialog(
                    status = updateStatus,
                    onUpdateClick = {
                        val downloadUrl =
                            when (val current = updateStatus) {
                                is UpdateStatus.Optional -> current.release.downloadUrl
                                is UpdateStatus.Breaking -> current.release.downloadUrl
                                UpdateStatus.None -> return@UpdateDialog
                            }
                        coroutineScope.launch(Dispatchers.IO) {
                            updateInstaller.downloadAndInstall(context, downloadUrl)
                        }
                    },
                    onDismiss = { appUpdateViewModel.dismissOptional() },
                )
            }
        }
    }
}
```

Add the required imports at the top of `MainActivity.kt`: `androidx.compose.runtime.LaunchedEffect`, `androidx.compose.runtime.remember`, `androidx.compose.runtime.rememberCoroutineScope`, `androidx.compose.ui.platform.LocalContext`, `kotlinx.coroutines.Dispatchers`, `kotlinx.coroutines.launch`, `dev.scuttle.inventory.ui.appupdate.AppUpdateViewModel`, `dev.scuttle.inventory.ui.appupdate.UpdateDialog`, `dev.scuttle.inventory.ui.appupdate.UpdateInstaller`, `dev.scuttle.inventory.data.appupdate.UpdateStatus`.

Note: `UpdateInstaller` has no constructor dependencies (`@Inject constructor()` with nothing to inject), so `remember { UpdateInstaller() }` is fine without Hilt injection here — it's a plain stateless helper, not injected via `hiltViewModel()`.

- [ ] **Step 8: Build and run the JVM test suite in full**

Run: `./gradlew :app:testDebugUnitTest`
Expected: PASS, no regressions across the whole suite.

- [ ] **Step 9: Build the debug APK to confirm the Compose/manifest changes compile**

Run: `./gradlew :app:assembleDebug`
Expected: BUILD SUCCESSFUL.

- [ ] **Step 10: Run ktlint/detekt to match this repo's CI gate**

Run: `./gradlew ktlintCheck detekt`
Expected: PASS (no new violations). Fix any formatting issues ktlint reports before committing.

- [ ] **Step 11: Commit**

```bash
git add app/src/main/java/dev/scuttle/inventory/ui/appupdate/ \
        app/src/main/java/dev/scuttle/inventory/MainActivity.kt \
        app/src/main/AndroidManifest.xml app/src/main/res/xml/file_paths.xml \
        app/src/main/res/values/strings.xml app/src/main/res/values-nl/strings.xml \
        app/src/test/java/dev/scuttle/inventory/AppUpdateViewModelTest.kt
git commit -m "feat: show update dialog on app open and support in-app APK install"
```

---

## Manual verification (post-implementation, not automatable)

After all tasks land, do one real-device pass since none of the above is covered by instrumented tests (per this repo's "no new instrumented flow test" testing decision in the spec):

1. Publish a test release via MCP (`create_app_release` with `version_code` one above the installed build, `publish: true`) and confirm the dialog appears on next app launch with the changelog text.
2. Publish a second test release with `is_breaking: true` and `min_supported_version_code` above the installed build; confirm the dialog is non-dismissable (back press and outside-tap do nothing) and only shows "Update now".
3. Tap "Update now" and confirm the APK downloads and the system package installer prompt appears (grant "install unknown apps" if prompted — first-time-only OS behavior).
4. Force the WorkManager job to run immediately for testing: `adb shell cmd jobscheduler run -f dev.scuttle.inventory <job-id>` (or temporarily reduce the interval to 15 minutes — WorkManager's minimum — for a manual closed-app test), close the app, and confirm the "App updates" notification appears with correct title/text and taps through to the app.
5. Confirm the notification channel appears in Android system settings under the app's notification categories, correctly labeled and independently toggleable.
