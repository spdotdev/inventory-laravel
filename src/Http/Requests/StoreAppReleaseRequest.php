<?php

namespace Spdotdev\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAppReleaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version_code' => ['required', 'integer', 'min:1', 'unique:inventory_app_releases,version_code'],
            'version_name' => ['required', 'string', 'max:50'],
            'is_breaking' => ['sometimes', 'boolean'],
            'min_supported_version_code' => ['nullable', 'integer', 'min:1'],
            'changelog' => ['required', 'string'],
            'download_url' => ['required', 'url'],
            'publish' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $isBreaking = (bool) $this->input('is_breaking', false);
            $hasMin = $this->filled('min_supported_version_code');

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
