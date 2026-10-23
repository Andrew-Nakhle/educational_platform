<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\CourseReview;
use App\Models\InstructorProfile;
use App\Models\InstructorReview;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Http\Requests\StoreCourseReviewRequest;
use App\Http\Requests\StoreInstructorReviewRequest;
use App\Http\Resources\Review\ReviewResource;

class ReviewController extends Controller
{
    // تقييم كورس
    public function rateCourse(StoreCourseReviewRequest $request, int $courseId)
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $course = Course::find($courseId);
        if (! $course) {
            return response()->json([
                'message' => 'Course not found.',

            ],404);
        }

        $enrolled = $request->user()
            ->enrollments()
            ->where('course_id', $course->id)
            ->exists();

        if (! $enrolled) {
            return response()->json([
                'message' => 'You must enroll in this course before rating it.',
            ], 403);
        }

        // One rating per student per course: a second rating updates it.
        $review = CourseReview::firstOrNew([
            'user_id' => $request->user()->id,
            'course_id' => $course->id,
        ]);

        $isUpdate = $review->exists;

        $review->fill($data);
        $review->save();

        return response()->json([
            'message' => $isUpdate
                ? 'Course rating updated successfully.'
                : 'Course rated successfully.',
            'data' => $review,
        ], $isUpdate ? 200 : 201);
    }

    // متوسط تقييمات كورس وعددها
    public function getCourseRating(int $courseId)
    {
       $course= Course::find($courseId);
       if (! $course) {
            return response()->json([
                'message' => 'Course not found.',

            ],404);
       }

        $reviews = CourseReview::where('course_id', $courseId);

        return response()->json([
            'course_id' => $courseId,
            'average_rating' => round(
                (float) (clone $reviews)->avg('rating'),
                2
            ),
            'ratings_count' => (clone $reviews)->count(),
            ...$this->ratingsPayload($reviews, 'Course ratings retrieved successfully.'),
        ]);
    }

    /**
     * `message` and `data` keys shared by the course and instructor
     * ratings endpoints (added next to the original keys).
     */
    private function ratingsPayload($reviews, string $message): array
    {
        $count = (clone $reviews)->count();
        $average = (clone $reviews)->avg('rating');

        return [
            'message' => $message,
            'data' => [
                'average' => $count > 0 ? round((float) $average, 2) : null,
                'count' => $count,
                'ratings' => ReviewResource::collection(
                    (clone $reviews)
                        ->with('user.studentProfile')
                        ->latest()
                        ->latest('id')
                        ->get()
                ),
            ],
        ];
    }

    // تقييم أستاذ من خلال كورس مسجل فيه الطالب
    public function rateInstructor(StoreInstructorReviewRequest $request, int $courseId)
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $course = Course::with('instructorProfile')
            ->find($courseId);
        if (! $course) {
            return response()->json([
                'message' => 'Course not found.',

            ],404);
        }

        $enrolled = $request->user()
            ->enrollments()
            ->where('course_id', $course->id)
            ->exists();

        if (! $enrolled) {
            return response()->json([
                'message' => 'You must enroll in this course before rating its instructor.',
            ], 403);
        }

        $instructor = $course->instructorProfile;

        if (! $instructor) {
            return response()->json([
                'message' => 'Instructor not found for this course.',
            ], 404);
        }

        // One rating per student per instructor: a second rating updates it.
        $review = InstructorReview::firstOrNew([
            'user_id' => $request->user()->id,
            'instructor_profile_id' => $instructor->id,
        ]);

        $isUpdate = $review->exists;

        $review->fill($data);
        $review->save();

        return response()->json([
            'message' => $isUpdate
                ? 'Instructor rating updated successfully.'
                : 'Instructor rated successfully.',
            'data' => $review,
        ], $isUpdate ? 200 : 201);
    }

    // متوسط تقييمات أستاذ وعددها
    public function getInstructorRating(int $instructorProfileId){
    $instructorProfile=InstructorProfile::find($instructorProfileId);
        if (!$instructorProfile) {
            return response()->json([
                'message' => 'Instructor profile not found.',
            ],404);
        }

        $reviews = InstructorReview::where(
            'instructor_profile_id',
            $instructorProfileId
        );

        return response()->json([
            'instructor_profile_id' => $instructorProfileId,
            'average_rating' => round(
                (float) (clone $reviews)->avg('rating'),
                2
            ),
            'ratings_count' => (clone $reviews)->count(),
            ...$this->ratingsPayload($reviews, 'Instructor ratings retrieved successfully.'),
        ]);
    }
}
