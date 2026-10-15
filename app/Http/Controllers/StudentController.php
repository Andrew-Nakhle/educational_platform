<?php

namespace App\Http\Controllers;

use App\Enums\Course\CourseStatus;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentStatus;
use App\Http\Requests\Student\UpdateStudentProfileRequest;
use App\Http\Resources\Auth\UserResource;
use App\Http\Resources\Course\CourseResource;
use App\Models\Course;
use App\Services\StripePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Log;

use Throwable;



class StudentController extends Controller{

    public function __construct(
        private StripePaymentService $stripePaymentService
    ){}
    public function updateProfile(
        UpdateStudentProfileRequest $request
    ): JsonResponse {
        $validated = $request->validated();

        if ($request->hasFile('avatar')) {
            $validated['avatar'] = $request->file('avatar')
                ->store('students/avatars', 'b2');
        }
        $user = DB::transaction(function () use ($request, $validated) {

            $user = $request->user();

            $user->update([
                'phone' => $validated['phone'],
                'gender' => $validated['gender'],
                'birthday' => $validated['birthday'],
            ]);

            $user->studentProfile()->updateOrCreate(
                [
                    'user_id' => $user->id,
                ],
                [
                    'first_name' => $validated['first_name'],
                    'father_name' => $validated['father_name'],
                    'last_name' => $validated['last_name'],
                    'avatar' => $validated['avatar'] ?? null,
                ]
            );

            return $user->load([
                'roles',
                'studentProfile',
            ]);
        });

        return response()->json([
            'message' => 'Student profile updated successfully.',
            'user' => new UserResource($user),
        ]);
    }

    public function enrollCourse(int $courseId)
    {
        $student = auth()->user();

        try {
            return DB::transaction(function () use ($courseId, $student) {
                $course = Course::query()
                    ->lockForUpdate()
                    ->find($courseId);

                if (! $course) {
                    return response()->json([
                        'message' => 'Course not found.',
                    ], 404);
                }

                if ($course->status !== CourseStatus::PUBLISHED) {
                    return response()->json([
                        'message' => 'This course is not available for enrollment.',
                    ], 422);
                }

                $alreadyEnrolled = $student->enrollments()
                    ->where('course_id', $course->id)
                    ->exists();

                if ($alreadyEnrolled) {
                    return response()->json([
                        'message' => 'You are already enrolled in this course.',
                    ], 422);
                }

                // Free course: create enrollment immediately.
                if ((float) $course->price === 0.0) {
                    $enrollment = $student->enrollments()->create([
                        'course_id' => $course->id,
                        'enrolled_at' => now(),
                    ]);

                    return response()->json([
                        'message' => 'You have enrolled in the course successfully.',
                        'enrollment' => $enrollment,
                    ], 201);
                }

                // Prevent creating multiple active payment attempts.
                $existingPayment = $student->payments()
                    ->where('course_id', $course->id)
                    ->whereIn('status', [
                        PaymentStatus::PENDING,
                        PaymentStatus::PROCESSING,
                    ])
                    ->exists();

                if ($existingPayment) {
                    return response()->json([
                        'message' => 'You already have a pending payment for this course.',
                    ], 422);
                }

                $payment = $student->payments()->create([
                    'course_id' => $course->id,
                    'payment_method' => 'card',
                    'provider' => PaymentProvider::STRIPE,
                    'amount' => $course->price,
                    'currency' => 'USD',
                    'status' => PaymentStatus::PENDING,
                ]);

                // If Stripe fails, the database transaction will roll back.
                $stripePayment = $this->stripePaymentService->createPayment(
                    (float) $payment->amount,
                    $payment->currency,
                    $payment->id
                );

                if (empty($stripePayment['checkout_url'])) {
                    throw new \RuntimeException(
                        'Stripe did not return a checkout URL.'
                    );
                }

                $payment->update([
                    'status' => PaymentStatus::PROCESSING,
                    'stripe_checkout_session_id' => $stripePayment['checkout_session_id'],
                ]);

                return response()->json([
                    'message' => 'Continue to payment to enroll in this course.',
                    'payment_id' => $payment->id,
                    'checkout_url' => $stripePayment['checkout_url'],
                ], 201);
            });
        } catch (Throwable $e) {
            Log::error('Course enrollment or payment initiation failed.', [
                'student_id' => $student->id,
                'course_id' => $courseId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'The operation failed. No enrollment or payment record was saved. Please try again.',
            ], 500);
        }
    }
    public function getMyCourses()
    {
        $student = auth()->user();

        $enrollments = $student->enrollments()
            ->with('course')
            ->get();

        $courses = $enrollments->pluck('course');

        return response()->json([
            'courses' => CourseResource::collection($courses),
        ]);
    }
}
