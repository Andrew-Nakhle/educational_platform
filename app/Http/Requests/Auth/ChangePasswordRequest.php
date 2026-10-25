<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
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
            'current_password' => [
                'required',
                'string',
            ],
            // PUT sends new_password(+_confirmation); the older POST sends
            // password(+_confirmation). Either one is accepted.
            'new_password' => [
                'required_without:password',
                'string',
                'min:8',
                'confirmed',
                'different:current_password',
            ],
            'password' => [
                'required_without:new_password',
                'string',
                'min:8',
                'confirmed',
                'different:current_password',
            ],
        ];
    }
}
