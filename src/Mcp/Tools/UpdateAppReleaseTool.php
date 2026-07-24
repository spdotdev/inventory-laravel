<?php

namespace Spdotdev\Inventory\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Routing\Route;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Spdotdev\Inventory\Http\Requests\UpdateAppReleaseRequest;
use Spdotdev\Inventory\Models\AppRelease;

#[Description('Update an existing app release, including publishing a draft (publish=true) after review.')]
class UpdateAppReleaseTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('App release ID (from list_app_releases).')->required(),
            'version_code' => $schema->integer(),
            'version_name' => $schema->string(),
            'is_breaking' => $schema->boolean(),
            'min_supported_version_code' => $schema->integer(),
            'changelog' => $schema->string(),
            'download_url' => $schema->string()->description('Must be https.'),
            'publish' => $schema->boolean(),
        ];
    }

    public function handle(Request $request): Response
    {
        $release = AppRelease::findOrFail($request->get('id'));

        $data = $request->except('id');

        $updateRequest = UpdateAppReleaseRequest::create('/', 'PATCH', $data);
        $updateRequest->setRouteResolver(function () use ($release, $updateRequest) {
            $route = new Route('PATCH', '/', []);
            $route->bind($updateRequest);
            $route->setParameter('appRelease', $release);

            return $route;
        });

        // See CreateAppReleaseTool for why withValidator() must be invoked
        // explicitly here: a plain validator(...) call skips
        // UpdateAppReleaseRequest's cross-field is_breaking check, which only
        // runs when Laravel resolves the FormRequest through the HTTP pipeline.
        $validator = validator($data, $updateRequest->rules());
        $updateRequest->withValidator($validator);
        $validated = $validator->validate();

        if (array_key_exists('publish', $validated)) {
            $validated['published_at'] = $validated['publish'] ? now() : null;
            unset($validated['publish']);
        }

        $release->update($validated);

        return Response::json($release->fresh()->toArray());
    }
}
