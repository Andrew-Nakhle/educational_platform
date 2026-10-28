<?php

namespace App\Http\Requests\Instructor;

use App\Enums\Course\DifficultyLevel;
use App\Enums\Course\Language;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCourseRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255',],
            'description' => ['required', 'string',],
            'price' => ['required', 'numeric', 'min:0',],
            'thumbnail' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('uploads.thumbnail_max_kb'),],
            'category_id' => ['required', 'integer', 'exists:categories,id',],
            'language' => [
                'required',
                Rule::enum(Language::class),
            ],
            'difficulty_level' => ['required', Rule::enum(DifficultyLevel::class),],
            'what_you_will_learn' => ['required', 'string',],

//            'duration_hours' => ['required', 'numeric', 'min:0',],
        ];
    }
}
