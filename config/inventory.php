<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Inventory host
    |--------------------------------------------------------------------------
    |
    | The domain this package answers on (landing page at `/`, API under
    | `/api/v1`). Defaults to the host application's own domain (parsed from
    | APP_URL), so it just works once mounted. Set INVENTORY_DOMAIN to serve it
    | on a dedicated subdomain instead, e.g. inventory.scuttle.dev.
    |
    */

    'domain' => env('INVENTORY_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),

    /*
    |--------------------------------------------------------------------------
    | Google Sign-In
    |--------------------------------------------------------------------------
    |
    | OAuth client ID(s) the Android app authenticates with. Google ID tokens
    | posted to /api/v1/auth/google are accepted only if their `aud` claim
    | matches one of these. Comma-separated env supports multiple clients.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Admin API token
    |--------------------------------------------------------------------------
    |
    | Static bearer token that protects /api/v1/admin/* routes. Set
    | INVENTORY_ADMIN_TOKEN to a long random string. Leave empty to disable
    | the admin API entirely.
    |
    */

    'admin_token' => env('INVENTORY_ADMIN_TOKEN', ''),

    'google' => [
        'client_ids' => array_values(array_filter(
            explode(',', (string) env('INVENTORY_GOOGLE_CLIENT_IDS', '')),
        )),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting (abuse protection)
    |--------------------------------------------------------------------------
    |
    | Throttles on the unauthenticated auth endpoints (register/login/google/
    | forgot-password) and on join-by-code, which are the brute-forceable
    | surfaces: credential stuffing, password spraying, and guessing household
    | join codes. Two layers per surface — a tight per-identity limit (email or
    | user id) to stop targeted attacks, and a looser per-IP limit to blunt
    | distributed attempts without locking out a shared NAT. All counts are
    | per-minute and env-tunable; set any to 0 to disable that layer.
    |
    */

    'rate_limits' => [
        // Auth endpoints, keyed by the submitted email + client IP.
        'auth_per_identity' => (int) env('INVENTORY_RL_AUTH_IDENTITY', 10),
        // Auth endpoints, keyed by client IP only (distributed-attempt cap).
        'auth_per_ip' => (int) env('INVENTORY_RL_AUTH_IP', 30),
        // households/join, keyed by the authenticated user (code-guessing cap).
        'join_per_user' => (int) env('INVENTORY_RL_JOIN_USER', 8),
        // POST /errors (unauthenticated crash intake), keyed by device_id + IP,
        // to stop a single client flooding the inventory_client_errors table.
        'errors_per_device' => (int) env('INVENTORY_RL_ERRORS_DEVICE', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Client-error log retention
    |--------------------------------------------------------------------------
    |
    | inventory:client-errors:prune deletes inventory_client_errors rows older
    | than this many days. Schedule it (e.g. daily) in the host app. 0 disables
    | pruning (retain forever).
    |
    */

    'client_errors_retention_days' => (int) env('INVENTORY_CLIENT_ERRORS_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Product images
    |--------------------------------------------------------------------------
    |
    | Filesystem disk product photos are stored on (POST .../products/{id}/image).
    | Defaults to the framework's `public` disk — the host app must have run
    | `php artisan storage:link` for the returned URLs to be reachable. Point this
    | at `s3` (or any configured disk) to offload storage. Max upload size in KB.
    |
    */

    'image_disk' => env('INVENTORY_IMAGE_DISK', 'public'),

    'image_max_kb' => (int) env('INVENTORY_IMAGE_MAX_KB', 5120),

    /*
    |--------------------------------------------------------------------------
    | Android app link
    |--------------------------------------------------------------------------
    |
    | Play Store (or direct APK) URL the /join/{code} web fallback points people
    | at to install the app. Optional — when unset the page just shows the join
    | code with instructions to enter it in the app.
    |
    */

    'android_app_url' => env('INVENTORY_ANDROID_APP_URL', ''),

];
