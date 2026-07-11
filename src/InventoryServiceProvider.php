<?php

namespace Spdotdev\Inventory;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Spdotdev\Inventory\Auth\GoogleIdTokenVerifier;
use Spdotdev\Inventory\Auth\GoogleTokenInfoVerifier;
use Spdotdev\Inventory\Console\Commands\AddHouseholdMemberCommand;
use Spdotdev\Inventory\Console\Commands\CreateHouseholdCommand;
use Spdotdev\Inventory\Console\Commands\ListHouseholdsCommand;
use Spdotdev\Inventory\Console\Commands\PruneClientErrorsCommand;
use Spdotdev\Inventory\Console\Commands\RegenerateJoinCodeCommand;
use Spdotdev\Inventory\Http\Middleware\EnsureAdminToken;
use Spdotdev\Inventory\Http\Middleware\EnsureHouseholdMember;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Observers\BroadcastHouseholdChange;

class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/inventory.php', 'inventory');

        // Web session guard on inventory_users (Phase 2 web UI) — separate from
        // the host app's own `web` guard/users and from the API's Sanctum tokens.
        config([
            'auth.guards.inventory' => ['driver' => 'session', 'provider' => 'inventory_users'],
            'auth.providers.inventory_users' => ['driver' => 'eloquent', 'model' => User::class],
        ]);

        // Default Google ID-token verifier (tokeninfo endpoint). Swap the
        // binding to a local JWT-cert verifier if call volume warrants it.
        $this->app->bind(GoogleIdTokenVerifier::class, function ($app) {
            /** @var list<string> $clientIds */
            $clientIds = (array) $app['config']->get('inventory.google.client_ids', []);

            // The web redirect-flow client mints id_tokens too — accept its
            // audience alongside the Android client IDs.
            $webClientId = (string) $app['config']->get('inventory.google.web.client_id', '');
            if ($webClientId !== '') {
                $clientIds[] = $webClientId;
            }

            return new GoogleTokenInfoVerifier(array_values(array_unique($clientIds)));
        });
    }

    public function boot(): void
    {
        // Return 401 JSON for unauthenticated API requests instead of redirecting to a
        // non-existent login route; unauthenticated WEB requests on the inventory domain
        // go to the package's own sign-in page (the host's route('login') may not exist).
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler) {
            $handler->renderable(function (AuthenticationException $e, Request $request) {
                if ($request->is('api/*') || $request->expectsJson()) {
                    return response()->json(['message' => 'Unauthenticated.'], 401);
                }
            });
        });

        // Unauthenticated WEB requests on the inventory domain go to the package's
        // own sign-in page (the host's route('login') may not exist). Other hosts
        // fall through to the framework default.
        Authenticate::redirectUsing(function (Request $request) {
            return $request->getHost() === config('inventory.domain')
                ? route('inventory.web.login.show')
                : null;
        });

        $this->registerBroadcasting();

        // Tenancy gate for /households/{household}/* routes.
        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('household.member', EnsureHouseholdMember::class);
        $router->aliasMiddleware('inventory.admin', EnsureAdminToken::class);

        $this->registerRateLimiters();

        // Landing page (web) + headless API (api/v1), both host-based routed
        // on config('inventory.domain').
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        // MCP admin server — only loaded when laravel/mcp is installed on the host.
        if (class_exists(Mcp::class)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/mcp.php');
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'inventory');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'inventory');

        // Package-owned tables (inventory_*) live here; empty until the schema
        // step. loadMigrationsFrom is a no-op while the directory has none.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/inventory.php' => config_path('inventory.php'),
        ], 'inventory-config');

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/inventory'),
        ], 'inventory-assets');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateHouseholdCommand::class,
                ListHouseholdsCommand::class,
                AddHouseholdMemberCommand::class,
                RegenerateJoinCodeCommand::class,
                PruneClientErrorsCommand::class,
            ]);
        }
    }

    /**
     * Live updates (Q-3): every mutation of the household tree broadcasts a
     * coarse HouseholdChanged ping on a private per-household channel; clients
     * re-fetch on receipt (server-authoritative — the ping carries no state).
     * With no broadcaster configured on the host, dispatching is a no-op, so
     * the package works unchanged without Reverb.
     */
    private function registerBroadcasting(): void
    {
        foreach ([Household::class, StorageLocation::class, Shelf::class, Product::class] as $model) {
            $model::observe(BroadcastHouseholdChange::class);
        }

        // Channel auth endpoint for the Android client: POST api/v1/broadcasting/auth
        // with the same Sanctum bearer token as the rest of the API.
        Broadcast::routes([
            'domain' => config('inventory.domain'),
            'prefix' => 'api/v1',
            'middleware' => ['api', 'auth:sanctum'],
        ]);

        // Channel auth endpoint for the web UI: POST /broadcasting/auth with the
        // session guard + web middleware (CSRF), so the Blade pages can subscribe
        // to the same channel. Distinct path from the api/v1 registration above.
        Broadcast::routes([
            'domain' => config('inventory.domain'),
            'middleware' => ['web', 'auth:inventory'],
        ]);

        // Same tenancy rule as household.member: members only. Guards must be
        // explicit — channel auth otherwise resolves the user via the host's
        // default guard (web) and would 403 every Sanctum-tokened client.
        Broadcast::channel('inventory.household.{householdId}', function ($user, int $householdId) {
            return $user instanceof User
                && $user->households()->whereKey($householdId)->exists();
        }, ['guards' => ['sanctum', 'inventory']]);
    }

    /**
     * Named rate limiters for the brute-forceable surfaces. Closures run per
     * request, so limits read live config (tests can override at runtime).
     * A returned array layers the limits: any one being exceeded yields a 429.
     * A limit configured to 0 is omitted, disabling that layer.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('inventory-auth', function (Request $request) {
            $config = $this->app['config'];
            $email = strtolower(trim((string) $request->input('email')));
            $ip = (string) $request->ip();

            $limits = [];
            if (($perIdentity = (int) $config->get('inventory.rate_limits.auth_per_identity')) > 0) {
                // Email may be absent (e.g. /auth/google) — fall back to the IP
                // so the per-identity layer still bounds those requests.
                $limits[] = Limit::perMinute($perIdentity)->by('auth|'.($email !== '' ? $email : $ip).'|'.$ip);
            }
            if (($perIp = (int) $config->get('inventory.rate_limits.auth_per_ip')) > 0) {
                $limits[] = Limit::perMinute($perIp)->by('auth-ip|'.$ip);
            }

            return $limits;
        });

        RateLimiter::for('inventory-join', function (Request $request) {
            $perUser = (int) $this->app['config']->get('inventory.rate_limits.join_per_user');
            if ($perUser <= 0) {
                return Limit::none();
            }

            // Join is authenticated; key by user id (fall back to IP defensively).
            $id = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute($perUser)->by('join|'.$id);
        });

        RateLimiter::for('inventory-errors', function (Request $request) {
            $perDevice = (int) $this->app['config']->get('inventory.rate_limits.errors_per_device');
            if ($perDevice <= 0) {
                return Limit::none();
            }

            // Unauthenticated crash intake — key by the client-supplied device_id
            // plus IP so one device (or host) can't flood the error table.
            $device = trim((string) $request->input('device_id'));
            $ip = (string) $request->ip();

            return Limit::perMinute($perDevice)->by('errors|'.($device !== '' ? $device : $ip).'|'.$ip);
        });
    }
}
