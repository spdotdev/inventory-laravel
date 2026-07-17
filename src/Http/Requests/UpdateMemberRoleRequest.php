<?php

namespace Spdotdev\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH households/{household}/members/{user}. `role` deliberately excludes
 * "owner" — becoming Owner only happens via POST .../transfer-ownership, so
 * there is exactly one code path that mints a new Owner.
 */
class UpdateMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(['admin', 'member'])],
        ];
    }
}
