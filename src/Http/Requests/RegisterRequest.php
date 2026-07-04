<?php

namespace Spdotdev\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the email to lowercase at the boundary so it is stored, looked up
     * (login), and reset consistently — otherwise register `Foo@x.com` then
     * login/reset with other casing silently misses on the case-sensitive SQLite
     * the package is CI-tested on (masked only by MySQL's default collation). W13.
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:inventory_users,email'],
            'password' => ['required', 'string', 'min:8'],
        ];
    }
}
