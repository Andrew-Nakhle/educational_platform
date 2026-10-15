<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
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
            'first_name' => ['required', 'string', 'max:100',],
            'father_name' => ['required', 'string', 'max:100',],
            'last_name' => ['required', 'string', 'max:100',],
            'gender' => ['required', Rule::in(['male', 'female']),],
            'birthday' => ['required', 'date', 'before_or_equal:today',],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone',],
            'email' => ['required', 'email', 'max:255', 'unique:users,email',],
            'password' => ['required', 'string', 'min:8', 'confirmed',],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp',],
        ];
    }
}
