<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInstructorReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'rating.required' => 'Rating is required.',
            'rating.integer' => 'Rating must be an integer.',
            'rating.between' => 'Rating must be between 1 and 5.',
            'comment.string' => 'Comment must be a string.',
            'comment.max' => 'Comment must not exceed 2000 characters.',
        ];
    }
}
