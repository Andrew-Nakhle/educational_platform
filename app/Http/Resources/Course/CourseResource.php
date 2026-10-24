<?php

namespace App\Http\Resources\Course;

use App\Models\LessonProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user('sanctum');
        $isEnrolled = $user !== null
            && $user->hasRole('student')
            && $user->enrollments()
                ->where('course_id', $this->id)
                ->exists();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price,

            'thumbnail' => $this->thumbnail
                ? Storage::disk('b2')->temporaryUrl(
                    $this->thumbnail,
                    now()->addMinutes(1000)
                )
                : null,

            'language' => $this->language?->value,
            'difficulty_level' => $this->difficulty_level?->value,
            'what_you_will_learn' => $this->what_you_will_learn,
            'status' => $this->status?->value,
            'duration_hours' => $this->duration_hours,

            'is_enrolled' => $isEnrolled,

            'category' => [
                'id' => $this->category?->id,
                'name' => $this->category?->name,
            ],

            'instructor' => [
                'id' => $this->instructorProfile?->id,
                'first_name' => $this->instructorProfile?->first_name,
                'father_name' => $this->instructorProfile?->father_name,
                'last_name' => $this->instructorProfile?->last_name,
            ],

            'sections' => $this->whenLoaded('sections', function () use (
                $user,
                $isEnrolled
            ) {
                return $this->sections->map(function ($section) use (
                    $user,
                    $isEnrolled
                ) {
                    return [
                        'id' => $section->id,
                        'title' => $section->title,
                        'description' => $section->description,
                        'sort_order' => $section->sort_order,

                        'lessons' => $section->lessons->map(
                            function ($lesson) use ($user, $isEnrolled) {
                                $completed = false;

                                if ($isEnrolled && $user !== null) {
                                    $completed = LessonProgress::query()
                                        ->where('user_id', $user->id)
                                        ->where('lesson_id', $lesson->id)
                                        ->whereNotNull('completed_at')
                                        ->exists();
                                }

                                return [
                                    'id' => $lesson->id,
                                    'title' => $lesson->title,
                                    'description' => $lesson->description,
                                    'duration_seconds' => $lesson->duration_seconds,
                                    'sort_order' => $lesson->sort_order,
                                    'completed' => $isEnrolled
                                        ? $completed
                                        : false,
                                ];
                            }
                        )->values(),
                    ];
                })->values();
            }),
        ];
    }
}
