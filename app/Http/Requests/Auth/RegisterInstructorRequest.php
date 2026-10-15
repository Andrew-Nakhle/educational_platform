<?php

namespace App\Http\Requests\Auth;

use App\Enums\Instructor\TeachingMajor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterInstructorRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'father_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'gender' => ['required', Rule::in(['male', 'female']),],
            'birthday' => ['required', 'date', 'before_or_equal:today',],
            'phone' => ['required', 'string', 'max:20',],
            'email' => ['required', 'email', 'max:255',],
            'password' => ['required', 'string', 'min:8', 'confirmed',],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp'],
            'teaching_major' => ['required', Rule::enum(TeachingMajor::class),],
            'bio' => ['nullable', 'string', 'max:2000',],
            'certifications' => ['nullable', 'array',],
            'certifications.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120',],
        ];
    }
}
