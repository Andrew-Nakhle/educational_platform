<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100',],

            'father_name' => ['required', 'string', 'max:100',],

            'last_name' => ['required', 'string', 'max:100',],

            'phone' => ['required', 'string', 'max:20', Rule::unique('users', 'phone')->ignore(auth()->id()),],

            'gender' => ['required', Rule::in(['male', 'female']),],

            // Optional on update (kept when not sent); still checked when sent.
            'birthday' => ['sometimes', 'required', 'date', 'before_or_equal:today',],

            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp',],
        ];
    }
}
