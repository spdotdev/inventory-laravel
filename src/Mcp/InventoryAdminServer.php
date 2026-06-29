<?php

namespace Spdotdev\Inventory\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;
use Spdotdev\Inventory\Mcp\Tools\DeleteHouseholdTool;
use Spdotdev\Inventory\Mcp\Tools\DeleteUserTool;
use Spdotdev\Inventory\Mcp\Tools\GetHouseholdTool;
use Spdotdev\Inventory\Mcp\Tools\GetUserTool;
use Spdotdev\Inventory\Mcp\Tools\ListHouseholdsTool;
use Spdotdev\Inventory\Mcp\Tools\ListUsersTool;
use Spdotdev\Inventory\Mcp\Tools\SearchUsersTool;

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
    ];
}
