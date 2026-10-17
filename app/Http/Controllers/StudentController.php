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

    public function enrollCourse(
        \Illuminate\Http\Request $request,
        int $courseId
    ) {
        $validated = $request->validate([
            'payment_flow' => ['required', 'in:web,mobile'],
        ]);

        $student = $request->user();

        try {
            // Step 1: Validate course and prepare payment record.
            $result = DB::transaction(function () use ($courseId, $student) {
                $course = Course::query()
                    ->lockForUpdate()
                    ->find($courseId);

                if (! $course) {
                    return [
                        'error' => 'Course not found.',
                        'status' => 404,
                    ];
                }

                if ($course->status !== CourseStatus::PUBLISHED) {
                    return [
                        'error' => 'This course is not available for enrollment.',
                        'status' => 422,
                    ];
                }

                if (
                    $student->enrollments()
                        ->where('course_id', $course->id)
                        ->exists()
                ) {
                    return [
                        'error' => 'You are already enrolled in this course.',
                        'status' => 422,
                    ];
                }

                // Free course: enroll immediately.
                if ((float) $course->price <= 0) {
                    $enrollment = $student->enrollments()->firstOrCreate(
                        ['course_id' => $course->id],
                        ['enrolled_at' => now()]
                    );

                    return [
                        'free' => true,
                        'enrollment' => $enrollment,
                    ];
                }

                // Reuse an existing pending/processing payment.
                $existingPayment = $student->payments()
                    ->where('course_id', $course->id)
                    ->whereIn('status', [
                        PaymentStatus::PENDING,
                        PaymentStatus::PROCESSING,
                    ])
                    ->latest()
                    ->first();

                if ($existingPayment) {
                    return [
                        'existing_payment' => $existingPayment,
                    ];
                }

                $payment = $student->payments()->create([
                    'course_id' => $course->id,
                    'payment_method' => 'card',
                    'provider' => PaymentProvider::STRIPE,
                    'amount' => $course->price,
                    'currency' => 'USD',
                    'status' => PaymentStatus::PENDING,
                ]);

                return [
                    'payment' => $payment,
                ];
            });

            // Handle validation errors.
            if (isset($result['error'])) {
                return response()->json([
                    'message' => $result['error'],
                ], $result['status']);
            }

            // Handle free course.
            if (isset($result['free'])) {
                return response()->json([
                    'message' => 'You have enrolled in the course successfully.',
                    'enrollment' => $result['enrollment'],
                ], 201);
            }

            $payment = $result['payment']
                ?? $result['existing_payment'];

            $isExistingPayment = isset($result['existing_payment']);

            // Step 2: Resume an existing payment.
            if ($isExistingPayment) {
                if ($validated['payment_flow'] === 'web') {
                    if (! $payment->stripe_checkout_session_id) {
                        return response()->json([
                            'message' => 'Existing payment has no Checkout Session. Please resolve it before retrying.',
                            'payment_id' => $payment->id,
                        ], 409);
                    }

                    $stripePayment = $this->stripePaymentService
                        ->retrieveCheckoutSession(
                            $payment->stripe_checkout_session_id
                        );

                    if (
                        $stripePayment['status'] === 'open'
                        && ! empty($stripePayment['checkout_url'])
                    ) {
                        return response()->json([
                            'message' => 'Continue your existing payment.',
                            'payment_id' => $payment->id,
                            'checkout_url' => $stripePayment['checkout_url'],
                        ]);
                    }

                    return response()->json([
                        'message' => 'The previous Checkout Session is no longer available.',
                        'payment_id' => $payment->id,
                        'checkout_status' => $stripePayment['status'],
                        'payment_status' => $stripePayment['payment_status'],
                    ], 409);
                }

                if ($validated['payment_flow'] === 'mobile') {
                    if (! $payment->provider_payment_id) {
                        return response()->json([
                            'message' => 'Existing payment has no PaymentIntent. Please resolve it before retrying.',
                            'payment_id' => $payment->id,
                        ], 409);
                    }

                    $intent = $this->stripePaymentService
                        ->retrievePaymentIntent(
                            $payment->provider_payment_id
                        );

                    if ($intent['status'] === 'succeeded') {
                        return response()->json([
                            'message' => 'Payment is awaiting server confirmation.',
                            'payment_id' => $payment->id,
                        ], 409);
                    }

                    if (! in_array($intent['status'], [
                        'requires_payment_method',
                        'requires_confirmation',
                        'requires_action',
                    ], true)) {
                        return response()->json([
                            'message' => 'This payment cannot be resumed.',
                            'payment_id' => $payment->id,
                            'payment_status' => $intent['status'],
                        ], 409);
                    }

                    return response()->json([
                        'message' => 'Continue your existing payment.',
                        'payment_id' => $payment->id,
                        'client_secret' => $intent['client_secret'],
                    ]);
                }
            }

            // Step 3: React Web uses Stripe Checkout.
            if ($validated['payment_flow'] === 'web') {
                $stripePayment = $this->stripePaymentService->createPayment(
                    (float) $payment->amount,
                    $payment->currency,
                    $payment->id
                );

                if (
                    empty($stripePayment['checkout_url'])
                    || empty($stripePayment['checkout_session_id'])
                ) {
                    throw new \RuntimeException(
                        'Stripe did not return a valid Checkout Session.'
                    );
                }

                $payment->update([
                    'status' => PaymentStatus::PROCESSING,
                    'stripe_checkout_session_id' =>
                        $stripePayment['checkout_session_id'],
                ]);

                return response()->json([
                    'message' => 'Continue to payment.',
                    'payment_id' => $payment->id,
                    'checkout_url' => $stripePayment['checkout_url'],
                ], 201);
            }

            // Step 4: Flutter uses Stripe PaymentIntent.
            $stripePayment = $this->stripePaymentService->createPaymentIntent(
                (float) $payment->amount,
                $payment->currency,
                $payment->id
            );

            if (
                empty($stripePayment['client_secret'])
                || empty($stripePayment['provider_payment_id'])
            ) {
                throw new \RuntimeException(
                    'Stripe did not return the PaymentIntent details.'
                );
            }

            $payment->update([
                'status' => PaymentStatus::PROCESSING,
                'provider_payment_id' =>
                    $stripePayment['provider_payment_id'],
            ]);

            return response()->json([
                'message' => 'Payment initialized successfully.',
                'payment_id' => $payment->id,
                'client_secret' => $stripePayment['client_secret'],
            ], 201);

        } catch (Throwable $e) {
            Log::error('Course enrollment or payment initiation failed.', [
                'student_id' => $student->id,
                'course_id' => $courseId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'The operation failed. Please try again.',
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
