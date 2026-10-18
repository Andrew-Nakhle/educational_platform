<?php

namespace App\Http\Resources\Course;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CourseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price,

            'thumbnail' => $this->thumbnail
                ? Storage::disk('b2')->temporaryUrl(
                    $this->thumbnail,
                    now()->addMinutes(30)
                )
                : null,
            'language' => $this->language,

            'difficulty_level' => $this->difficulty_level?->value,

            'what_you_will_learn' => $this->what_you_will_learn,

//            'duration_hours' => $this->duration_hours,

            'status' => $this->status?->value,
//////////////////////
            'category' => [
                'id' => $this->category?->id,
                'name' => $this->category?->name,
            ],
///////////////////
            'instructor' => [
                'id' => $this->instructorProfile?->id,
                'first_name' => $this->instructorProfile?->first_name,
                'father_name' => $this->instructorProfile?->father_name,
                'last_name' => $this->instructorProfile?->last_name,

            ],
            'duration_hours' => $this->duration_hours,

            'sections' => $this->whenLoaded('sections', function () {
                return $this->sections->map(function ($section) {
                    return [
                        'id' => $section->id,
                        'title' => $section->title,
                        'description' => $section->description,
                        'sort_order' => $section->sort_order,

                        'lessons' => $section->lessons->map(function ($lesson) {
                            return [
                                'id' => $lesson->id,
                                'title' => $lesson->title,
                                'description' => $lesson->description,
                                'duration_seconds' => $lesson->duration_seconds,
                                'sort_order' => $lesson->sort_order,
                            ];
                        })->values(),
                    ];
                })->values();
            }),

        ];
    }
}
