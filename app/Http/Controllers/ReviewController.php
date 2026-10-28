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
        $data = $request->validated();
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

        $exists = CourseReview::where('user_id', $request->user()->id)
            ->where('course_id', $course->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'You have already rated this course.',
            ], 409);
        }

        $review = CourseReview::create([
            'user_id' => $request->user()->id,
            'course_id' => $course->id,
            ...$data,
        ]);

        return response()->json([
            'message' => 'Course rated successfully.',
            'data' => $review,
        ], 201);
    }

    // Change the student's existing rating of a course.
    public function updateCourseRating(StoreCourseReviewRequest $request, int $courseId)
    {
        $data = $request->validated();

        $course = Course::find($courseId);
        if (! $course) {
            return response()->json([
                'message' => 'Course not found.',
            ], 404);
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

        $review = CourseReview::where('user_id', $request->user()->id)
            ->where('course_id', $course->id)
            ->first();

        if (! $review) {
            return response()->json([
                'message' => 'You have not rated this course yet.',
            ], 404);
        }

        // PUT replaces the rating: a missing comment clears it.
        $review->update([
            'comment' => null,
            ...$data,
        ]);

        return response()->json([
            'message' => 'Course rating updated successfully.',
            'data' => $review,
        ]);
    }

    // متوسط تقييمات كورس وعددها
    public function getCourseRating(Request $request, int $courseId)
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
            ...$this->ratingsPayload($reviews, 'Course ratings retrieved successfully.', $request),
        ]);
    }

    /**
     * `message` and `data` keys shared by the course and instructor
     * ratings endpoints (added next to the original keys).
     */
    private function ratingsPayload($reviews, string $message, Request $request): array
    {
        $count = (clone $reviews)->count();
        $average = (clone $reviews)->avg('rating');

        // Optional token: the viewer's own rating, null for guests and
        // non-students.
        $user = $request->user('sanctum');
        $myRating = $user !== null && $user->hasRole('student')
            ? (clone $reviews)
                ->with('user.studentProfile')
                ->where('user_id', $user->id)
                ->first()
            : null;

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
                'my_rating' => $myRating ? new ReviewResource($myRating) : null,
            ],
        ];
    }

    // تقييم أستاذ من خلال كورس مسجل فيه الطالب
    public function rateInstructor(StoreInstructorReviewRequest $request, int $courseId)
    {
        $data = $request->validated();

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

        $exists = InstructorReview::where(
            'user_id',
            $request->user()->id
        )
            ->where('instructor_profile_id', $instructor->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'You have already rated this instructor.',
            ], 409);
        }

        $review = InstructorReview::create([
            'user_id' => $request->user()->id,
            'instructor_profile_id' => $instructor->id,
            ...$data,
        ]);


        return response()->json([
            'message' => 'Instructor rated successfully.',
            'data' => $review,
        ], 201);
    }

    // Change the student's existing rating of a course's instructor.
    public function updateInstructorRating(StoreInstructorReviewRequest $request, int $courseId)
    {
        $data = $request->validated();

        $course = Course::with('instructorProfile')->find($courseId);
        if (! $course) {
            return response()->json([
                'message' => 'Course not found.',
            ], 404);
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

        $review = InstructorReview::where('user_id', $request->user()->id)
            ->where('instructor_profile_id', $instructor->id)
            ->first();

        if (! $review) {
            return response()->json([
                'message' => 'You have not rated this instructor yet.',
            ], 404);
        }

        // PUT replaces the rating: a missing comment clears it.
        $review->update([
            'comment' => null,
            ...$data,
        ]);

        return response()->json([
            'message' => 'Instructor rating updated successfully.',
            'data' => $review,
        ]);
    }

    // متوسط تقييمات أستاذ وعددها
    public function getInstructorRating(Request $request, int $instructorProfileId){
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
            ...$this->ratingsPayload($reviews, 'Instructor ratings retrieved successfully.', $request),
        ]);
    }
}
