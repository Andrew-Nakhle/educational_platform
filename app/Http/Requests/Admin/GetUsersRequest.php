<?php

namespace App\Http\Requests\Admin;

use App\Enums\User\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GetUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => ['nullable', 'string', Rule::in(['instructor', 'student','admin']),],

            'status' => ['nullable', Rule::enum(UserStatus::class),],

            'page' => ['sometimes', 'integer', 'min:1',],

            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50',],

            'search' => ['nullable', 'string', 'max:100',],
        ];
    }
}
