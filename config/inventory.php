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

];
