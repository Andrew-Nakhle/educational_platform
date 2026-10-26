<?php

namespace Tests\Feature;

use App\Enums\Course\CourseStatus;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentStatus;
use App\Models\InstructorReview;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesTestData;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * feature/mvp-endpoints-5: fixes from the end-to-end smoke test.
 * Stripe is always the fake HTTP client (no network); every account and
 * value is made up.
 */
class MvpEndpoints5Test extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    private const UNAVAILABLE = ['message' => 'Payments are temporarily unavailable. Please try again later.'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTestData();

        config([
            'services.stripe.secret' => 'sk_test_fake',
            'app.frontend_url' => 'https://frontend.example.test',
        ]);

        $this->stripe = FakeStripeHttpClient::install();
    }

    protected function tearDown(): void
    {
        FakeStripeHttpClient::uninstall();

        parent::tearDown();
    }

    private function enrollPaid(User $student, int $courseId, string $flow = 'web')
    {
        return $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$courseId}/enroll", ['payment_flow' => $flow]);
    }

    private function pendingPayment(User $student, int $courseId, array $attributes = []): Payment
    {
        return Payment::create(array_merge([
            'user_id' => $student->id,
            'course_id' => $courseId,
            'payment_method' => 'card',
            'provider' => PaymentProvider::STRIPE,
            'amount' => '49.99',
            'currency' => 'USD',
            'status' => PaymentStatus::PENDING,
        ], $attributes));
    }

    // ---- 1. Stuck payments ---------------------------------------------------------------

    public function test_a_stripe_error_marks_the_new_payment_failed_and_a_retry_starts_a_new_one(): void
    {
        $student = $this->makeStudent();
        $course = $this->makeCourse(attributes: ['price' => '49.99']);

        $this->stripe->failWithServerError();
        $this->enrollPaid($student, $course->id)
            ->assertStatus(500)
            ->assertExactJson(['message' => 'The operation failed. Please try again.']);

        $this->assertSame(PaymentStatus::FAILED, Payment::sole()->status);

        // Stripe works again: no 409, a fresh payment and Checkout Session.
        $this->stripe->failWith = null;
        $this->enrollPaid($student, $course->id)
            ->assertCreated()
            ->assertJsonPath('message', 'Continue to payment.')
            ->assertJsonPath('checkout_url', 'https://checkout.stripe.test/c/pay/cs_test_example');

        $this->assertSame(
            [PaymentStatus::FAILED, PaymentStatus::PROCESSING],
            Payment::orderBy('id')->get()->pluck('status')->all()
        );
    }

    public function test_a_failed_payment_intent_marks_the_payment_failed_too(): void
    {
        $student = $this->makeStudent();
        $course = $this->makeCourse(attributes: ['price' => '49.99']);

        $this->stripe->failWithServerError();
        $this->enrollPaid($student, $course->id, 'mobile')->assertStatus(500);

        $this->assertSame(PaymentStatus::FAILED, Payment::sole()->status);
    }

    public function test_an_abandoned_pending_payment_without_session_or_intent_is_replaced(): void
    {
        $student = $this->makeStudent();
        $course = $this->makeCourse(attributes: ['price' => '49.99']);
        $abandoned = $this->pendingPayment($student, $course->id);

        $this->enrollPaid($student, $course->id)
            ->assertCreated()
            ->assertJsonPath('message', 'Continue to payment.');

        $this->assertSame(PaymentStatus::FAILED, $abandoned->fresh()->status);
        $new = Payment::whereKeyNot($abandoned->id)->sole();
        $this->assertSame(PaymentStatus::PROCESSING, $new->status);
        $this->assertSame('cs_test_example', $new->stripe_checkout_session_id);
    }

    public function test_an_abandoned_payment_is_replaced_for_the_mobile_flow_too(): void
    {
        $student = $this->makeStudent();
        $course = $this->makeCourse(attributes: ['price' => '49.99']);
        $abandoned = $this->pendingPayment($student, $course->id);

        $this->enrollPaid($student, $course->id, 'mobile')
            ->assertCreated()
            ->assertJsonPath('message', 'Payment initialized successfully.');

        $this->assertSame(PaymentStatus::FAILED, $abandoned->fresh()->status);
    }

    public function test_a_pending_payment_with_a_live_session_is_resumed_not_replaced(): void
    {
        $student = $this->makeStudent();
        $course = $this->makeCourse(attributes: ['price' => '49.99']);
        $live = $this->pendingPayment($student, $course->id, [
            'status' => PaymentStatus::PROCESSING,
            'stripe_checkout_session_id' => 'cs_test_example',
        ]);

        $this->enrollPaid($student, $course->id)
            ->assertOk()
            ->assertJsonPath('message', 'Continue your existing payment.')
            ->assertJsonPath('payment_id', $live->id);

        $this->assertSame(1, Payment::count());
        $this->assertSame(PaymentStatus::PROCESSING, $live->fresh()->status);
    }

    public function test_a_pending_payment_whose_session_ended_keeps_the_409(): void
    {
        $student = $this->makeStudent();
        $course = $this->makeCourse(attributes: ['price' => '49.99']);
        $this->pendingPayment($student, $course->id, ['stripe_checkout_session_id' => 'cs_test_example']);
        $this->stripe->sessionStatus = 'expired';

        $this->enrollPaid($student, $course->id)
            ->assertStatus(409)
            ->assertJsonPath('message', 'The previous Checkout Session is no longer available.');

        $this->assertSame(1, Payment::count());
    }

    public function test_a_pending_payment_for_the_other_flow_keeps_its_409(): void
    {
        $student = $this->makeStudent();
        $course = $this->makeCourse(attributes: ['price' => '49.99']);
        $this->pendingPayment($student, $course->id, ['stripe_checkout_session_id' => 'cs_test_example']);

        $this->enrollPaid($student, $course->id, 'mobile')
            ->assertStatus(409)
            ->assertJsonPath('message', 'Existing payment has no PaymentIntent. Please resolve it before retrying.');
    }

    // ---- 2. Stripe not configured or refusing the key -----------------------------------

    public function test_an_empty_or_blank_stripe_secret_answers_503_and_calls_nothing(): void
    {
        foreach (['', '   '] as $secret) {
            config(['services.stripe.secret' => $secret]);
            $student = $this->makeStudent();
            $course = $this->makeCourse(attributes: ['price' => '49.99']);

            $this->enrollPaid($student, $course->id)->assertStatus(503)->assertExactJson(self::UNAVAILABLE);
            $this->enrollPaid($student, $course->id, 'mobile')->assertStatus(503)->assertExactJson(self::UNAVAILABLE);
        }

        $this->assertCount(0, $this->stripe->requests);
        $this->assertSame(0, Payment::whereIn('status', [PaymentStatus::PENDING, PaymentStatus::PROCESSING])->count());
    }

    public function test_a_stripe_authentication_error_answers_503_and_logs_without_the_key(): void
    {
        Log::spy();
        $student = $this->makeStudent();
        $course = $this->makeCourse(attributes: ['price' => '49.99']);
        $this->stripe->failWithAuthenticationError();

        $response = $this->enrollPaid($student, $course->id)
            ->assertStatus(503)
            ->assertExactJson(self::UNAVAILABLE);

        $this->assertStringNotContainsString('sk_test', $response->getContent());
        $this->assertSame(PaymentStatus::FAILED, Payment::sole()->status);

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context) {
            $logged = json_encode($context);

            return str_contains($message, 'Payments are unavailable')
                && $context['exception'] === \Stripe\Exception\AuthenticationException::class
                && str_contains($logged, '[redacted]')
                && ! str_contains($logged, 'sk_test');
        })->once();
    }

    public function test_free_courses_still_enroll_without_stripe(): void
    {
        config(['services.stripe.secret' => '']);
        $student = $this->makeStudent();
        $course = $this->makeCourse(attributes: ['price' => 0]);

        $this->enrollPaid($student, $course->id)->assertCreated();
        $this->assertSame(0, Payment::count());
    }

    // ---- 3. Own birthday; optional on profile updates ------------------------------------

    public function test_profile_login_and_register_show_the_own_birthday(): void
    {
        $student = $this->makeStudent(['birthday' => '2001-02-03']);

        // Login and register first: nobody is authenticated there yet.
        $this->postJson('/api/auth/login', ['email' => $student->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('user.birthday', '2001-02-03');

        $this->postJson('/api/auth/register', [
            'first_name' => 'Maya', 'father_name' => 'Nabil', 'last_name' => 'Aziz',
            'gender' => 'female', 'birthday' => '2000-05-06', 'phone' => '0933000111',
            'email' => 'maya.new@example.test', 'password' => 'fake-pass-123', 'password_confirmation' => 'fake-pass-123',
        ])->assertCreated()->assertJsonPath('user.birthday', '2000-05-06');

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/auth/profile')
            ->assertOk()
            ->assertJsonPath('birthday', '2001-02-03')
            ->assertJsonPath('age', $student->birthday->age);
    }

    public function test_admin_lists_never_show_other_users_birthdays(): void
    {
        $student = $this->makeStudent(['birthday' => '2001-02-03']);
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'sanctum');

        $list = $this->getJson('/api/admin/users')->assertOk()->json();
        $this->assertStringNotContainsString('2001-02-03', json_encode($list));

        $this->getJson("/api/admin/users/{$student->id}")
            ->assertOk()
            ->assertJsonMissingPath('birthday');
    }

    public function test_student_profile_saves_without_birthday_and_keeps_it(): void
    {
        $student = $this->makeStudent(['birthday' => '2001-02-03']);
        $fields = ['first_name' => 'Maya', 'father_name' => 'Nabil', 'last_name' => 'Aziz', 'phone' => '0933111222', 'gender' => 'female'];

        $this->actingAs($student, 'sanctum')
            ->putJson('/api/student/profile', $fields)
            ->assertOk()
            ->assertJsonPath('data.birthday', '2001-02-03')
            ->assertJsonPath('data.profile.first_name', 'Maya');

        $this->assertSame('2001-02-03', $student->fresh()->birthday->format('Y-m-d'));

        // Still validated when sent.
        $this->putJson('/api/student/profile', [...$fields, 'birthday' => 'not-a-date'])
            ->assertStatus(422)->assertJsonValidationErrors('birthday');
        $this->putJson('/api/student/profile', [...$fields, 'birthday' => now()->addDay()->format('Y-m-d')])
            ->assertStatus(422)->assertJsonValidationErrors('birthday');
        $this->putJson('/api/student/profile', [...$fields, 'birthday' => null])
            ->assertStatus(422)->assertJsonValidationErrors('birthday');

        $this->putJson('/api/student/profile', [...$fields, 'birthday' => '1999-09-09'])
            ->assertOk()->assertJsonPath('data.birthday', '1999-09-09');
    }

    public function test_instructor_profile_saves_without_birthday_and_shows_it(): void
    {
        $instructor = $this->makeInstructor(['birthday' => '1988-08-08']);

        $this->actingAs($instructor, 'sanctum')
            ->putJson('/api/instructor/profile', [
                'first_name' => 'Omar', 'father_name' => 'Tarek', 'last_name' => 'Saeed',
                'phone' => '0933444555', 'gender' => 'male', 'teaching_major' => 'software_engineering',
            ])
            ->assertOk()
            ->assertJsonPath('data.birthday', '1988-08-08');

        $this->assertSame('1988-08-08', $instructor->fresh()->birthday->format('Y-m-d'));
    }

    public function test_registration_still_requires_the_birthday(): void
    {
        $this->postJson('/api/auth/register', [
            'first_name' => 'Maya', 'father_name' => 'Nabil', 'last_name' => 'Aziz',
            'gender' => 'female', 'phone' => '0933000222',
            'email' => 'maya.nobirthday@example.test', 'password' => 'fake-pass-123', 'password_confirmation' => 'fake-pass-123',
        ])->assertStatus(422)->assertJsonValidationErrors('birthday');
    }

    // ---- 4. No lessons: no submit, no approve --------------------------------------------

    public function test_a_course_needs_a_lesson_to_be_submitted(): void
    {
        $instructor = $this->makeInstructor();
        $course = $this->makeCourse($instructor, ['status' => CourseStatus::DRAFT]);
        $this->actingAs($instructor, 'sanctum');

        $message = ['message' => 'Add at least one section with at least one lesson before submitting the course for publishing.'];

        // No section.
        $this->postJson("/api/instructor/courses/{$course->id}/submit")->assertStatus(422)->assertExactJson($message);
        // A section without lessons.
        $section = $this->makeSection($course);
        $this->postJson("/api/instructor/courses/{$course->id}/submit")->assertStatus(422)->assertExactJson($message);
        $this->assertSame(CourseStatus::DRAFT, $course->fresh()->status);

        $this->makeLesson($section);
        $this->postJson("/api/instructor/courses/{$course->id}/submit")->assertOk()->assertJsonPath('status', 'pending_publish');
    }

    public function test_the_status_rule_still_comes_first_on_submit(): void
    {
        $instructor = $this->makeInstructor();
        $course = $this->makeCourse($instructor, ['status' => CourseStatus::PUBLISHED]);

        $this->actingAs($instructor, 'sanctum')
            ->postJson("/api/instructor/courses/{$course->id}/submit")
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Only draft or rejected courses can be submitted for publishing.']);
    }

    public function test_admin_cannot_approve_a_course_without_lessons(): void
    {
        $course = $this->makeCourse(attributes: ['status' => CourseStatus::PENDING_PUBLISH]);
        $this->actingAs($this->makeAdmin(), 'sanctum');

        $this->patchJson("/api/admin/courses/{$course->id}/approve")
            ->assertStatus(422)
            ->assertExactJson(['message' => 'A course with no lessons cannot be approved.']);
        $this->assertSame(CourseStatus::PENDING_PUBLISH, $course->fresh()->status);

        $this->giveLesson($course);
        $this->patchJson("/api/admin/courses/{$course->id}/approve")->assertOk()->assertJsonPath('status', 'published');
    }

    // ---- 5. Counts and the own instructor rating ----------------------------------------

    public function test_sections_and_lessons_counts_on_lists_and_details(): void
    {
        $instructor = $this->makeInstructor();
        $course = $this->makeCourse($instructor);
        $first = $this->makeSection($course);
        $this->makeLesson($first);
        $this->makeLesson($first);
        $this->makeSection($course); // empty section
        $empty = $this->makeCourse($instructor, ['title' => 'Empty', 'status' => CourseStatus::DRAFT]);

        $this->actingAs($instructor, 'sanctum');
        $list = collect($this->getJson('/api/instructor/courses')->assertOk()->json('courses'))->keyBy('id');
        $this->assertSame([2, 2], [$list[$course->id]['sections_count'], $list[$course->id]['lessons_count']]);
        $this->assertSame([0, 0], [$list[$empty->id]['sections_count'], $list[$empty->id]['lessons_count']]);
        $this->getJson('/api/instructor/courses?page=1')->assertOk()->assertJsonStructure(['data' => [['sections_count', 'lessons_count']]]);
        $this->getJson("/api/instructor/courses/{$course->id}")
            ->assertJsonPath('course.sections_count', 2)->assertJsonPath('course.lessons_count', 2);

        $this->actingAs($this->makeAdmin(), 'sanctum');
        $adminList = collect($this->getJson('/api/admin/courses')->assertOk()->json('courses'))->keyBy('id');
        $this->assertSame(2, $adminList[$course->id]['lessons_count']);
        $this->assertSame(0, $adminList[$empty->id]['lessons_count']);
        $this->getJson("/api/admin/courses/{$course->id}")
            ->assertJsonPath('course.sections_count', 2)->assertJsonPath('course.lessons_count', 2);

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/student/courses/{$course->id}")
            ->assertJsonPath('data.sections_count', 2)->assertJsonPath('data.lessons_count', 2);
    }

    public function test_students_count_on_the_public_catalog(): void
    {
        $course = $this->makeCourse();
        $other = $this->makeCourse(attributes: ['title' => 'Nobody yet']);
        $this->enroll($this->makeStudent(), $course);
        $this->enroll($this->makeStudent(), $course);
        $this->enroll($this->makeStudent(), $course);

        $list = collect($this->getJson('/api/student/courses')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(3, $list[$course->id]['students_count']);
        $this->assertSame(0, $list[$other->id]['students_count']);
        $this->getJson("/api/student/courses/{$course->id}")->assertJsonPath('data.students_count', 3);
        $this->getJson('/api/student/courses?page=1')->assertJsonStructure(['data' => [['students_count']], 'meta']);
    }

    public function test_my_courses_has_my_instructor_rating(): void
    {
        $student = $this->makeStudent();
        $rated = $this->makeInstructor();
        $notRated = $this->makeInstructor();
        $a = $this->makeCourse($rated, ['title' => 'A']);
        $b = $this->makeCourse($rated, ['title' => 'B']);
        $c = $this->makeCourse($notRated, ['title' => 'C']);
        foreach ([$a, $b, $c] as $course) {
            $this->enroll($student, $course);
        }
        InstructorReview::create(['user_id' => $student->id, 'instructor_profile_id' => $rated->instructorProfile->id, 'rating' => 4, 'comment' => 'Clear.']);
        // Another student's rating isn't "mine".
        InstructorReview::create(['user_id' => $this->makeStudent()->id, 'instructor_profile_id' => $notRated->instructorProfile->id, 'rating' => 2]);

        $courses = collect($this->actingAs($student, 'sanctum')->getJson('/api/student/my-courses')->assertOk()->json('courses'))->keyBy('id');

        $this->assertSame(4, $courses[$a->id]['my_instructor_rating']);
        $this->assertSame(4, $courses[$b->id]['my_instructor_rating']);
        $this->assertNull($courses[$c->id]['my_instructor_rating']);
        $this->assertArrayHasKey('my_rating', $courses[$a->id]); // unchanged
    }

    // ---- 6. Reset password never reveals an account -------------------------------------

    public function test_reset_password_answers_the_same_for_an_unknown_email_and_a_bad_token(): void
    {
        $student = $this->makeStudent();
        $expected = [
            'message' => 'This password reset link is invalid or has expired.',
            'errors' => ['email' => ['This password reset link is invalid or has expired.']],
        ];
        $body = ['token' => 'fake-token', 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password'];

        $unknown = $this->postJson('/api/auth/reset-password', [...$body, 'email' => 'nobody@example.test'])
            ->assertStatus(422)->assertExactJson($expected);
        $badToken = $this->postJson('/api/auth/reset-password', [...$body, 'email' => $student->email])
            ->assertStatus(422)->assertExactJson($expected);

        $this->assertSame($unknown->getContent(), $badToken->getContent());
        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('brand-new-password', $student->fresh()->password));
    }

    public function test_forgot_password_stays_neutral(): void
    {
        Notification::fake();
        $student = $this->makeStudent();
        $neutral = ['message' => 'If the email is registered, a password reset link will be sent.'];

        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.test'])->assertOk()->assertExactJson($neutral);
        $this->postJson('/api/auth/forgot-password', ['email' => $student->email])->assertOk()->assertExactJson($neutral);

        Notification::assertSentTo($student, ResetPasswordNotification::class);
    }
}
