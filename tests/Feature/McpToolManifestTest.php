<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Laravel\Mcp\Server\Tool;
use ReflectionClass;
use Spdotdev\Inventory\Mcp\InventoryAdminServer;
use Spdotdev\Inventory\Tests\TestCase;

/**
 * Guards the canonical MCP tool manifest (docs/specs/mcp-tools.json) against the
 * embedded server's actual tool surface. The standalone stdio server
 * (spdotdev/inventory-mcp) runs the mirror-image check in its CI against the same
 * manifest, so the two surfaces can only drift if BOTH guards are ignored.
 *
 * Wire names differ by convention — embedded: kebab-case + "-tool" suffix
 * (list-users-tool), standalone: snake_case (list_users) — so the manifest keys
 * are normalized logical names (strip "-tool", kebab -> snake).
 */
class McpToolManifestTest extends TestCase
{
    /** @return array<string, array{scope: string, destructive: bool, params: array<int, array{name: string, type: string, required: bool}>}> */
    private function manifestTools(): array
    {
        $path = dirname(__DIR__, 2).'/docs/specs/mcp-tools.json';
        $this->assertFileExists($path);

        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($manifest['tools'] ?? null, 'Manifest must have a tools array.');

        $tools = [];
        foreach ($manifest['tools'] as $tool) {
            $tools[$tool['key']] = [
                'scope' => $tool['scope'] ?? 'admin',
                'destructive' => $tool['destructive'],
                'params' => $tool['params'],
            ];
        }

        return $tools;
    }

    /**
     * The embedded server is protected only by the static admin token — it has
     * no per-user auth context, so 'household' scoped tools (personal Sanctum
     * token, standalone stdio server only) are exempt from the 1:1 equality
     * check below.
     *
     * @param  array<string, array{scope: string, destructive: bool, params: array<int, array{name: string, type: string, required: bool}>}>  $tools
     * @return array<string, array{scope: string, destructive: bool, params: array<int, array{name: string, type: string, required: bool}>}>
     */
    private function adminScoped(array $tools): array
    {
        return array_filter($tools, fn (array $tool): bool => $tool['scope'] === 'admin');
    }

    /** @return array<string, array{params: array<int, array{name: string, type: string, required: bool}>}> */
    private function serverTools(): array
    {
        $property = (new ReflectionClass(InventoryAdminServer::class))->getProperty('tools');

        /** @var array<int, class-string<Tool>> $classes */
        $classes = $property->getDefaultValue();

        $tools = [];
        foreach ($classes as $class) {
            /** @var Tool $tool */
            $tool = $this->app->make($class);
            $wire = $tool->toArray();

            $key = str_replace('-', '_', (string) preg_replace('/-tool$/', '', $wire['name']));

            $schema = $wire['inputSchema'];
            $required = $schema['required'] ?? [];
            $params = [];
            foreach ((array) $schema['properties'] as $name => $spec) {
                $params[] = [
                    'name' => $name,
                    'type' => $spec['type'],
                    'required' => in_array($name, $required, true),
                ];
            }

            $tools[$key] = ['params' => $params];
        }

        return $tools;
    }

    public function test_embedded_tool_surface_matches_the_manifest(): void
    {
        $manifest = $this->adminScoped($this->manifestTools());
        $server = $this->serverTools();

        $this->assertSame(
            array_keys($manifest),
            array_keys($server),
            'Embedded MCP tool set (or order) differs from docs/specs/mcp-tools.json — update the manifest AND the standalone inventory-mcp server together.',
        );

        foreach ($manifest as $key => $expected) {
            $this->assertSame(
                $expected['params'],
                $server[$key]['params'],
                "Tool '{$key}' input schema differs from the manifest.",
            );
        }
    }

    public function test_delete_tools_are_flagged_destructive_in_the_manifest(): void
    {
        // laravel/mcp v0.8 has no destructive/read-only tool annotations, so the
        // embedded side can't express hints; the manifest's destructive flags are
        // validated structurally here (every delete_* tool, plus any explicitly
        // allow-listed non-delete tool whose real-world effect is destructive —
        // e.g. it can zero out/remove a resource's presence) and against real
        // annotations by the standalone server's conformance test.
        $nonDeleteDestructive = ['remove_product_quantity', 'leave_household', 'remove_member'];

        foreach ($this->manifestTools() as $key => $tool) {
            $this->assertSame(
                str_starts_with($key, 'delete_') || in_array($key, $nonDeleteDestructive, true),
                $tool['destructive'],
                "Manifest destructive flag for '{$key}' doesn't match its delete_*/allow-listed naming.",
            );
        }
    }
}
