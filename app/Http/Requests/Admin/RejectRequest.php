<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Optional reason for rejecting a course or an instructor application.
 */
class RejectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => [
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }
}
