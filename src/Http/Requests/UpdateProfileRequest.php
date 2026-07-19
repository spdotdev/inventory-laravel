<?php

namespace Spdotdev\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Partial self-service profile update (PATCH semantics): only keys present in
 * the request are applied. Email uniqueness excludes the caller's own row so
 * re-submitting an unchanged email doesn't false-positive as taken.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the email to lowercase at the boundary, same as
     * RegisterRequest/LoginRequest (W13) — otherwise a case-only change would
     * store inconsistently with how login looks the value up.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => Str::lower((string) $this->input('email'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('inventory_users', 'email')->ignore($this->user()?->getKey()),
            ],
        ];
    }
}
