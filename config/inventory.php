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
    ],

];
