<?php

namespace Spdotdev\Inventory;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Spdotdev\Inventory\Auth\GoogleIdTokenVerifier;
use Spdotdev\Inventory\Auth\GoogleTokenInfoVerifier;
use Spdotdev\Inventory\Console\Commands\CreateHouseholdCommand;
use Spdotdev\Inventory\Http\Middleware\EnsureAdminToken;
use Spdotdev\Inventory\Http\Middleware\EnsureHouseholdMember;

class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/inventory.php', 'inventory');

        // Default Google ID-token verifier (tokeninfo endpoint). Swap the
        // binding to a local JWT-cert verifier if call volume warrants it.
        $this->app->bind(GoogleIdTokenVerifier::class, function ($app) {
            /** @var list<string> $clientIds */
            $clientIds = (array) $app['config']->get('inventory.google.client_ids', []);

            return new GoogleTokenInfoVerifier($clientIds);
        });
    }

    public function boot(): void
    {
        // Return 401 JSON for unauthenticated API requests instead of redirecting to a non-existent login route.
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler) {
            $handler->renderable(function (AuthenticationException $e, Request $request) {
                if ($request->is('api/*') || $request->expectsJson()) {
                    return response()->json(['message' => 'Unauthenticated.'], 401);
                }
            });
        });

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
            ]);
        }
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
    }
}
