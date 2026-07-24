<?php

namespace Spdotdev\Inventory\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Spdotdev\Inventory\Models\AppRelease;

#[Description('List all app releases, including unpublished drafts, newest version_code first.')]
class ListAppReleasesTool extends Tool
{
    public function handle(Request $request): Response
    {
        $releases = AppRelease::query()->orderByDesc('version_code')->get();

        return Response::json($releases->toArray());
    }
}
