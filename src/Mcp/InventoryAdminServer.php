<?php

namespace Spdotdev\Inventory\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;
use Spdotdev\Inventory\Mcp\Tools\CreateAppReleaseTool;
use Spdotdev\Inventory\Mcp\Tools\DeleteHouseholdTool;
use Spdotdev\Inventory\Mcp\Tools\DeleteUserTool;
use Spdotdev\Inventory\Mcp\Tools\GetHouseholdTool;
use Spdotdev\Inventory\Mcp\Tools\GetUserTool;
use Spdotdev\Inventory\Mcp\Tools\ListAppReleasesTool;
use Spdotdev\Inventory\Mcp\Tools\ListHouseholdsTool;
use Spdotdev\Inventory\Mcp\Tools\ListUsersTool;
use Spdotdev\Inventory\Mcp\Tools\SearchUsersTool;
use Spdotdev\Inventory\Mcp\Tools\UpdateAppReleaseTool;

/**
 * The EMBEDDED (HTTP, in-process, Eloquent-backed) admin MCP surface. The same seven
 * tools also ship as a standalone stdio server — https://github.com/spdotdev/inventory-mcp
 * — which calls the REST admin API instead. Keep the two tool sets in sync when either
 * changes. The shared surface is pinned by the machine-readable manifest
 * docs/specs/mcp-tools.json: McpToolManifestTest guards this side, inventory-mcp's
 * conformance test guards the other. Grow the manifest and both guards together.
 */
#[Name('Inventory Admin')]
#[Version('1.0.0')]
#[Instructions('Admin tools for managing inventory users and households. All operations are destructive — deletions cascade.')]
class InventoryAdminServer extends Server
{
    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        ListUsersTool::class,
        SearchUsersTool::class,
        GetUserTool::class,
        DeleteUserTool::class,
        ListHouseholdsTool::class,
        GetHouseholdTool::class,
        DeleteHouseholdTool::class,
        ListAppReleasesTool::class,
        CreateAppReleaseTool::class,
        UpdateAppReleaseTool::class,
    ];
}
