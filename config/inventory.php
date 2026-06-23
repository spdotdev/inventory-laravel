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

];
