<?php

namespace Cofa\ApiDocs\Tests\Fixtures\Requests;

use Cofa\ApiDocs\Tests\Fixtures\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => 'required|string|min:2|max:60',
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => 'required|string|min:8|confirmed',
            'age' => 'nullable|integer|between:18,120',
            'status' => ['required', Rule::in(['active', 'suspended'])],
            'role' => ['nullable', Rule::enum(UserStatus::class)],
            'tags' => 'array|max:5',
            'tags.*' => 'string|max:20',
            'address' => 'required|array',
            'address.city' => 'required|string',
            'address.zip' => 'nullable|string|size:5',
            'contacts' => 'array',
            'contacts.*.name' => 'required|string',
            'contacts.*.phone' => 'nullable|string',
            'avatar' => 'nullable|image|max:2048',
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function bodyParameters(): array
    {
        return [
            'name' => ['description' => 'The full name of the user.', 'example' => 'Ada Lovelace'],
            'email' => ['description' => 'Used to sign in.'],
        ];
    }
}
