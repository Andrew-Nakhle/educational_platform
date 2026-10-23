<?php

namespace App\Http\Resources\Review;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A course or instructor review in the public ratings lists.
 */
class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->user?->studentProfile;

        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'student' => [
                'first_name' => $profile?->first_name,
                'last_name' => $profile?->last_name,
            ],
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
