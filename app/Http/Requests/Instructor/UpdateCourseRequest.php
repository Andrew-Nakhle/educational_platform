<?php

namespace App\Http\Requests\Instructor;

use App\Enums\Course\DifficultyLevel;
use App\Enums\Course\Language;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCourseRequest extends FormRequest
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
            'category_id' => ['sometimes', 'exists:categories,id'],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'language' => ['sometimes', Rule::enum(Language::class),],
            'difficulty_level' => ['sometimes', Rule::enum(DifficultyLevel::class)],
            'what_you_will_learn' => ['sometimes', 'string'],

            'thumbnail' => ['sometimes', 'image', 'mimes:jpg,jpeg,png,webp'],
        ];
    }
}
