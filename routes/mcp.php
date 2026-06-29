<?php

use Laravel\Mcp\Facades\Mcp;
use Spdotdev\Inventory\Mcp\InventoryAdminServer;

// Admin MCP server — protected by the same static bearer token as the REST
// admin API (INVENTORY_ADMIN_TOKEN). Endpoint: /mcp on the inventory domain.
Mcp::web('/mcp', InventoryAdminServer::class)
    ->middleware(['inventory.admin']);
