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

        $review = CourseReview::firstOrNew([
            'user_id' => $request->user()->id,
            'course_id' => $course->id,
        ]);

        if ($review->exists) {
            return response()->json([
                'message' => 'You have already rated this course.',
            ], 409);
        }

        $review->fill($data);
        $review->save();

        return response()->json([
            'message' => 'Course rated successfully.',
            'data' => $review,
        ], 201);
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
        ]);
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

        $review = InstructorReview::firstOrNew([
            'user_id' => $request->user()->id,
            'instructor_profile_id' => $instructor->id,
        ]);

        if ($review->exists) {
            return response()->json([
                'message' => 'You have already rated this instructor.',
            ], 409);
        }

        $review->fill($data);
        $review->save();

        return response()->json([
            'message' => 'Instructor rated successfully.',
            'data' => $review,
        ], 201);
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
        ]);
    }
}
