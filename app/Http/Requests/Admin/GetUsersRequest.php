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
        ];
    }
}
