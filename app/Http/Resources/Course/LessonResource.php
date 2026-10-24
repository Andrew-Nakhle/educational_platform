<?php

namespace App\Http\Resources\Course;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LessonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'section_id' => $this->section_id,
            'title' => $this->title,
            'description' => $this->description,
            'duration_seconds' => $this->duration_seconds,
            'sort_order' => $this->sort_order,

            'video_status' => $this->whenLoaded('video', function () {
                return $this->video?->status?->value;
            }),

            'video' => $this->whenLoaded('video', function () {
                if (! $this->video) {
                    return null;
                }

                return [
                    'id' => $this->video->id,
                    'status' => $this->video->status?->value,
                    'playlist_path' => $this->video->playlist_path,
                    'duration_seconds' => $this->video->duration_seconds,
                ];
            }),
        ];
    }
}
