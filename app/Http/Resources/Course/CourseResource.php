<?php

namespace App\Http\Resources\Course;

use App\Models\LessonProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CourseResource extends JsonResource
{
    /**
     * Lesson IDs the current student has completed. When set (enrolled
     * student), every lesson in the details gets a `completed` flag.
     *
     * @var array<int, int>|null
     */
    protected ?array $completedLessonIds = null;

    public function withCompletedLessonIds(?array $lessonIds): static
    {
        $this->completedLessonIds = $lessonIds;

        return $this;
    }

    /**
     * Adds `reject_reason`; only for the course owner's own list and
     * details (instructor endpoints).
     */
    protected bool $includeRejectReason = false;

    public function withRejectReason(): static
    {
        $this->includeRejectReason = true;

        return $this;
    }

    /**
     * Collection for the instructor's own courses (with `reject_reason`).
     */
    public static function ownedCollection($resource): AnonymousResourceCollection
    {
        $collection = static::collection($resource);
        $collection->collection->each(fn (self $course) => $course->withRejectReason());

        return $collection;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user('sanctum');

        // Student/public endpoints preload is_enrolled (withExists).
        $isEnrolled = array_key_exists('is_enrolled', $this->resource->getAttributes())
            ? (bool) $this->resource->is_enrolled
            : $user !== null
                && $user->hasRole('student')
                && $user->enrollments()
                    ->where('course_id', $this->id)
                    ->exists();

        $completedLessonIds = $this->completedLessonIds ?? [];

        if ($this->completedLessonIds === null
            && $isEnrolled
            && $user !== null
            && $this->relationLoaded('sections')
        ) {
            $completedLessonIds = LessonProgress::query()
                ->where('user_id', $user->id)
                ->whereIn(
                    'lesson_id',
                    $this->sections->flatMap(fn ($section) => $section->lessons)->pluck('id')
                )
                ->whereNotNull('completed_at')
                ->pluck('lesson_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price,

            'thumbnail' => $this->thumbnail
                ? Storage::disk(config('filesystems.upload_disk'))->temporaryUrl(
                    $this->thumbnail,
                    now()->addMinutes(
                        (int) (config('filesystems.signed_url_minutes') ?? 1000)
                    )
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

            // Public instructor data only (no email or phone).
            'instructor' => [
                'id' => $this->instructorProfile?->id,
                'first_name' => $this->instructorProfile?->first_name,
                'father_name' => $this->instructorProfile?->father_name,
                'last_name' => $this->instructorProfile?->last_name,
                'avatar' => $this->instructorProfile?->avatar
                    ? Storage::disk(config('filesystems.upload_disk'))->temporaryUrl(
                        $this->instructorProfile->avatar,
                        now()->addMinutes(
                            (int) (config('filesystems.signed_url_minutes') ?? 30)
                        )
                    )
                    : null,
                'teaching_major' => $this->instructorProfile?->teaching_major?->value,
                'bio' => $this->instructorProfile?->bio,
            ],

            // Instructor and admin endpoints load withCount('enrollments').
            'students_count' => $this->when(
                array_key_exists('enrollments_count', $this->resource->getAttributes()),
                fn () => (int) $this->resource->enrollments_count
            ),

            // Instructor and admin lists, and every course details endpoint,
            // load withCount(['sections', 'lessons']).
            'sections_count' => $this->when(
                array_key_exists('sections_count', $this->resource->getAttributes()),
                fn () => (int) $this->resource->sections_count
            ),
            'lessons_count' => $this->when(
                array_key_exists('lessons_count', $this->resource->getAttributes()),
                fn () => (int) $this->resource->lessons_count
            ),

            'reject_reason' => $this->when(
                $this->includeRejectReason,
                fn () => $this->resource->reject_reason
            ),

            'rating_average' => $this->ratingAverage(),
            'ratings_count' => $this->ratingsCount(),

            'sections' => $this->whenLoaded('sections', function () use (
                $completedLessonIds
            ) {
                return $this->sections->map(function ($section) use (
                    $completedLessonIds
                ) {
                    return [
                        'id' => $section->id,
                        'title' => $section->title,
                        'description' => $section->description,
                        'sort_order' => $section->sort_order,

                        'lessons' => $section->lessons->map(
                            function ($lesson) use ($completedLessonIds) {
                                $data = [
                                    'id' => $lesson->id,
                                    'title' => $lesson->title,
                                    'description' => $lesson->description,
                                    'duration_seconds' => $lesson->duration_seconds,
                                    'sort_order' => $lesson->sort_order,
                                    'completed' => in_array(
                                        $lesson->id,
                                        $completedLessonIds,
                                        true
                                    ),
                                ];

                                // Admin/instructor details load the video.
                                if ($lesson->relationLoaded('video')) {
                                    $data['video_status'] = $lesson->video?->status?->value;
                                }

                                return $data;
                            }
                        )->values(),
                    ];
                })->values();
            }),
        ];
    }

    private function ratingsCount(): int
    {
        if (array_key_exists('reviews_count', $this->resource->getAttributes())) {
            return (int) $this->resource->reviews_count;
        }

        return $this->resource->reviews()->count();
    }

    private function ratingAverage(): ?float
    {
        $average = array_key_exists('reviews_avg_rating', $this->resource->getAttributes())
            ? $this->resource->reviews_avg_rating
            : $this->resource->reviews()->avg('rating');

        return $average === null ? null : round((float) $average, 2);
    }
}
