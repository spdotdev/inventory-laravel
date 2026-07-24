<?php

namespace Spdotdev\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Spdotdev\Inventory\Models\AppRelease;

class UpdateAppReleaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $release = $this->route('appRelease');
        $releaseId = is_object($release) ? $release->id : $release;

        return [
            'version_code' => ['sometimes', 'integer', 'min:1', 'unique:inventory_app_releases,version_code,'.$releaseId],
            'version_name' => ['sometimes', 'string', 'max:50'],
            'is_breaking' => ['sometimes', 'boolean'],
            'min_supported_version_code' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'changelog' => ['sometimes', 'string'],
            'download_url' => ['sometimes', 'url:https'],
            'publish' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->has('is_breaking') && ! $this->has('min_supported_version_code')) {
                return;
            }

            /** @var AppRelease $existing */
            $existing = $this->route('appRelease');
            $isBreaking = $this->boolean('is_breaking', $existing->is_breaking);
            $hasMin = $this->has('min_supported_version_code')
                ? $this->filled('min_supported_version_code')
                : $existing->min_supported_version_code !== null;

            if ($isBreaking && ! $hasMin) {
                $validator->errors()->add(
                    'min_supported_version_code',
                    'min_supported_version_code is required when is_breaking is true.',
                );
            }

            if (! $isBreaking && $hasMin) {
                $validator->errors()->add(
                    'min_supported_version_code',
                    'min_supported_version_code must be omitted when is_breaking is false.',
                );
            }
        });
    }
}
