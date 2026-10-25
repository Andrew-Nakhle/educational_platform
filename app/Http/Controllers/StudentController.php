<?php

namespace App\Http\Controllers;

use App\Enums\Course\CourseStatus;
use App\Enums\Course\VideoStatus;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentStatus;
use App\Http\Requests\Student\GetCoursesRequest;
use App\Http\Requests\Student\UpdateStudentProfileRequest;
use App\Http\Resources\Admin\CategoryResource;
use App\Http\Resources\Auth\UserResource;
use App\Http\Resources\Course\CourseResource;
use App\Models\Category;
use App\Models\Course;
use App\Models\CourseReview;
use App\Models\Lesson;
use App\Services\CourseProgressService;
use App\Models\LessonProgress;
use App\Services\StripePaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Log;

use Illuminate\Support\Facades\Storage;
use mysql_xdevapi\Collection;
use Throwable;



class StudentController extends Controller{

    public function __construct(
        private StripePaymentService $stripePaymentService,
        private CourseProgressService $courseProgressService
    ){}
    public function updateProfile(
        UpdateStudentProfileRequest $request
    ): JsonResponse {
        $validated = $request->validated();

        if ($request->hasFile('avatar')) {
            $validated['avatar'] = $request->file('avatar')
                ->store('students/avatars', config('filesystems.upload_disk'));
        }
        $user = DB::transaction(function () use ($request, $validated) {

            $user = $request->user();

            $user->update([
                'phone' => $validated['phone'],
                'gender' => $validated['gender'],
                'birthday' => $validated['birthday'],
            ]);

            $profileData = [
                'first_name' => $validated['first_name'],
                'father_name' => $validated['father_name'],
                'last_name' => $validated['last_name'],
            ];

            // Keep the current avatar unless a new file was uploaded.
            if (isset($validated['avatar'])) {
                $profileData['avatar'] = $validated['avatar'];
            }

            $user->studentProfile()->updateOrCreate(
                [
                    'user_id' => $user->id,
                ],
                $profileData
            );

            return $user->load([
                'roles',
                'studentProfile',
            ]);
        });

        $resource = new UserResource($user);

        return response()->json([
            'message' => 'Student profile updated successfully.',
            'user' => $resource,
            'data' => $resource,
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
                    $payment->id,
                    $courseId
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




            public function getMyCourses(Request $request)
    {
        $student = auth()->user();

        $enrollments = $student->enrollments()
            ->whereHas('course')
            ->with([
                'course.category',
                'course.instructorProfile',
                'course.sections.lessons',
            ])
            ->latest('enrolled_at')
            ->get();

        $progress = $this->courseProgressService->forCourses(
            $student,
            $enrollments->pluck('course_id')->all()
        );

        // The student's own course rating (1-5) per course, or null.
        $myRatings = CourseReview::query()
            ->where('user_id', $student->id)
            ->whereIn('course_id', $enrollments->pluck('course_id'))
            ->pluck('rating', 'course_id');

        $courses = $enrollments->map(function ($enrollment) use ($progress, $request, $myRatings) {
            $courseProgress = $progress[$enrollment->course_id];

            return array_merge(
                (new CourseResource($enrollment->course))->resolve($request),
                [
                    'enrolled_at' => $enrollment->enrolled_at?->toISOString(),
                    'last_lesson_id' => $courseProgress['last_lesson_id'],
                    'progress' => $courseProgress['progress'],
                    'my_rating' => isset($myRatings[$enrollment->course_id])
                        ? (int) $myRatings[$enrollment->course_id]
                        : null,
                ]
            );
        })->values();

        return response()->json([
            'message' => 'Enrolled courses retrieved successfully.',
            'courses' => $courses,
            'data' => $courses,
        ]);
    }



    public function getCourses(GetCoursesRequest $request)
    {
        $validated = $request->validated();

        $query = Course::query()
            ->where('status', CourseStatus::PUBLISHED->value);

        $this->withCatalogAttributes($query, $request);

        if (! empty($validated['category_id'])) {
            $query->where(
                'category_id',
                $validated['category_id']
            );
        }

        if (! empty($validated['search'])) {
            $search = trim($validated['search']);

            $query->where('title', 'like', "%{$search}%");
        }

        // With ?page= the response adds `meta` and honours per_page;
        // without it, it stays exactly as before (12 per page, no meta).
        $paginate = $request->has('page');

        $query->latest();

        if ($paginate) {
            // Stable pages when several courses share created_at.
            $query->latest('id');
        }

        $courses = $query
            ->paginate($paginate ? (int) ($validated['per_page'] ?? 12) : 12)
            ->withQueryString();

        $response = [
            'message' => 'Courses retrieved successfully.',
            'data' => CourseResource::collection($courses),
        ];

        if ($paginate) {
            $response['meta'] = $this->paginationMeta($courses);
        }

        return response()->json($response);
    }
    //هاد التابع لعرض تفاصيل الكورس يعني بس ابحث عن كورسات وجيب مجموعة واكبس عليه بستدعي هاد التابع لعرض التفاصيل
    public function showCourse(Request $request, int $courseId)
    {
        $query = Course::query()
            ->where('status', CourseStatus::PUBLISHED->value);

        $this->withCatalogAttributes($query, $request);

        $course = $query
            ->with([
                'category',
                'instructorProfile',
                'sections' => function ($query) {
                    $query->orderBy('sort_order');
                },
                'sections.lessons' => function ($query) {
                    $query->orderBy('sort_order');
                },
            ])
            ->findOrFail($courseId);

        $completedLessonIds = $course->is_enrolled
            ? $this->courseProgressService->completedLessonIds(
                $request->user('sanctum'),
                $course->id
            )
            : null;

        return response()->json([
            'message' => 'Course details retrieved successfully.',
            'data' => (new CourseResource($course))
                ->withCompletedLessonIds($completedLessonIds),
        ]);
    }

    /**
     * Ratings for the course list/details, plus `is_enrolled` for the
     * optional Sanctum token (no token, or not a student: false).
     */
    private function withCatalogAttributes(Builder $query, Request $request): void
    {
        $user = $request->user('sanctum');

        $studentId = $user && $user->hasRole('student')
            ? $user->id
            : 0;

        $query
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->withExists([
                'enrollments as is_enrolled' => fn ($enrollments) =>
                    $enrollments->where('user_id', $studentId),
            ]);
    }

    public function getLessonVideo(int $lessonId)
    {
        $student = auth()->user();

        $lesson = Lesson::query()
            ->with(['section.course', 'video'])
            ->find($lessonId);
        if(!$lesson){
            return response()->json([
                'message' => 'Lesson not found.',
            ],404);
        }

        $course = $lesson->section->course;

        $isEnrolled = $student->enrollments()
            ->where('course_id', $course->id)
            ->exists();

        if (! $isEnrolled) {
            return response()->json([
                'message' => 'You are not enrolled in this course.',
            ], 403);
        }

        $video = $lesson->video;

        if (! $video || $video->status !== VideoStatus::READY) {
            return response()->json([
                'message' => 'Video is not available yet.',
            ], 404);
        }

        $videoUrl = Storage::disk(config('filesystems.upload_disk'))->temporaryUrl(
            $video->playlist_path,
            now()->addMinutes(30)
        );

        return response()->json([
            'message' => 'Video URL retrieved successfully.',
            'data' => [
                'lesson_id' => $lesson->id,
                'title' => $lesson->title,
                'video_url' => $videoUrl,
                'expires_in_minutes' => 30,
            ],
        ], 200);
    }




    public function completeLesson(int $lessonId): JsonResponse
    {
        $student = auth()->user();

        $lesson = Lesson::with('section.course')->find($lessonId);

        if (! $lesson) {
            return response()->json([
                'message' => 'Lesson not found.',
            ], 404);
        }

        $course = $lesson->section->course;

        $isEnrolled = $student->enrollments()
            ->where('course_id', $course->id)
            ->exists();

        if (! $isEnrolled) {
            return response()->json([
                'message' => 'You are not enrolled in this course.',
            ], 403);
        }

        LessonProgress::firstOrCreate(
            [
                'user_id' => $student->id,
                'lesson_id' => $lesson->id,
            ],
            [
                'completed_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Lesson marked as completed.',
            'data' => [
                'lesson_id' => $lesson->id,
                'completed' => true,
                'progress' => $this->getCourseProgress($student->id, $course),
            ],
        ]);
    }

    public function uncompleteLesson(int $lessonId): JsonResponse
    {
        $student = auth()->user();

        $lesson = Lesson::with('section.course')->find($lessonId);

        if (! $lesson) {
            return response()->json([
                'message' => 'Lesson not found.',
            ], 404);
        }

        $course = $lesson->section->course;

        $isEnrolled = $student->enrollments()
            ->where('course_id', $course->id)
            ->exists();

        if (! $isEnrolled) {
            return response()->json([
                'message' => 'You are not enrolled in this course.',
            ], 403);
        }

        LessonProgress::where('user_id', $student->id)
            ->where('lesson_id', $lesson->id)
            ->delete();

        return response()->json([
            'message' => 'Lesson marked as incomplete.',
            'data' => [
                'lesson_id' => $lesson->id,
                'completed' => false,
                'progress' => $this->getCourseProgress($student->id, $course),
            ],
        ]);
    }

    private function getCourseProgress(int $studentId, Course $course): array
    {
        $totalLessons = Lesson::whereHas('section', function ($query) use ($course) {
            $query->where('course_id', $course->id);
        })->count();

        $completedLessons = LessonProgress::where('user_id', $studentId)
            ->whereHas('lesson.section', function ($query) use ($course) {
                $query->where('course_id', $course->id);
            })
            ->whereNotNull('completed_at')
            ->count();

        return [
            'course_id' => $course->id,
            'completed_lessons' => $completedLessons,
            'total_lessons' => $totalLessons,
            'percent' => $totalLessons > 0
                ? (int) round(($completedLessons / $totalLessons) * 100)
                : 0,
        ];
    }


    public function getAllCategories()
    {
        $categories = Category::all();

        return CategoryResource::collection($categories);
    }
}
