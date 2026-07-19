<?php

namespace Spdotdev\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;
use Spdotdev\Inventory\Models\User;

/**
 * Changing password while authenticated requires confirming the current
 * password (unlike the enumeration-safe "forgot password" email flow, which
 * never touches the current one).
 */
class UpdatePasswordRequest extends FormRequest
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
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var User|null $user */
            $user = $this->user();
            $current = (string) $this->input('current_password');

            if ($user === null || $user->password === null || ! Hash::check($current, $user->password)) {
                $validator->errors()->add('current_password', __('auth.password'));
            }
        });
    }
}
