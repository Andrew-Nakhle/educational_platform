<?php

namespace App\Http\Requests\Instructor;

use Illuminate\Foundation\Http\FormRequest;

class StoreLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],

            'description' => ['nullable', 'string'],

            'video' => self::videoRules(),
        ];
    }

    /**
     * Rules for a lesson video upload (also used when replacing it).
     *
     * @return array<int, string>
     */
    public static function videoRules(): array
    {
        return [
            'required',
            'file',
            'mimes:mp4,mov,webm',
            'max:'.config('uploads.video_max_kb'),
        ];
    }
}
