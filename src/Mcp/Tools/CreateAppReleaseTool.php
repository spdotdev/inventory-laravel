<?php

namespace Spdotdev\Inventory\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Spdotdev\Inventory\Http\Requests\StoreAppReleaseRequest;
use Spdotdev\Inventory\Models\AppRelease;

#[Description('Create a new Android app release entry. Set is_breaking + min_supported_version_code for a release that requires users below that version to update before continuing. Set publish=true to make it immediately visible to the app\'s update check, or leave it false to create a draft reviewable via list_app_releases first.')]
class CreateAppReleaseTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'version_code' => $schema->integer()->description("Matches Android's versionCode.")->required(),
            'version_name' => $schema->string()->description("Matches Android's versionName, e.g. 0.1.22.")->required(),
            'is_breaking' => $schema->boolean()->description('True if this release requires a mandatory update.'),
            'min_supported_version_code' => $schema->integer()->description('Required when is_breaking is true; installs below this are hard-blocked.'),
            'changelog' => $schema->string()->description('Shown in the update dialog and (truncated) the notification.')->required(),
            'download_url' => $schema->string()->description('GitHub prerelease APK asset URL (must be https).')->required(),
            'publish' => $schema->boolean()->description('Publish immediately instead of creating a draft.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->all();

        // A plain validator(...) call skips StoreAppReleaseRequest's own
        // withValidator() cross-field check (is_breaking <-> the
        // min_supported_version_code requirement) since that hook only runs
        // when Laravel resolves the FormRequest through the HTTP pipeline.
        // Build the FormRequest and invoke both explicitly so this embedded
        // tool enforces the identical rule the HTTP admin endpoint does.
        $formRequest = StoreAppReleaseRequest::create('/', 'POST', $data);
        $validator = validator($data, $formRequest->rules());
        $formRequest->withValidator($validator);
        $validated = $validator->validate();

        $publish = (bool) ($validated['publish'] ?? false);
        unset($validated['publish']);
        $validated['published_at'] = $publish ? now() : null;

        $release = AppRelease::create($validated);

        return Response::json($release->toArray());
    }
}
