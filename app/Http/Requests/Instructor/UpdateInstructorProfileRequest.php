<?php

namespace App\Http\Requests\Instructor;

use App\Enums\Instructor\TeachingMajor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInstructorProfileRequest extends FormRequest
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
            'birthday' => ['required', 'date', 'before_or_equal:today',],

            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp',],
            'teaching_major' => ['required', Rule::enum(TeachingMajor::class),],
            'bio' => ['nullable', 'string', 'max:3000',],
        ];
    }
}
