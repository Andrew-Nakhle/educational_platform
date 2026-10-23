<?php

namespace Tests\Feature;

use App\Enums\Course\CourseStatus;
use App\Enums\Payment\PaymentStatus;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

class EnrollmentTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    private FakeStripeHttpClient $stripe;

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

    public function test_public_routes_work_without_stripe_secret(): void
    {
        config(['services.stripe.secret' => null]);

        $this->makeCourse();

        $this->getJson('/api/categories')->assertOk();
        $this->getJson('/api/student/courses')->assertOk();
    }

    public function test_student_routes_without_payment_work_without_stripe_secret(): void
    {
        config(['services.stripe.secret' => null]);

        $student = $this->makeStudent();
        $course = $this->makeCourse();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/enroll", ['payment_flow' => 'web'])
            ->assertCreated()
            ->assertJsonPath('message', 'You have enrolled in the course successfully.');

        $this->getJson('/api/student/my-courses')->assertOk();
    }

    public function test_free_course_enrolls_immediately(): void
    {
        $student = $this->makeStudent();
        $course = $this->makeCourse(attributes: ['price' => 0]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/enroll", ['payment_flow' => 'web'])
            ->assertCreated()
            ->assertJsonPath('enrollment.course_id', $course->id);

        $this->assertDatabaseHas('enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
        ]);
        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_paid_course_creates_checkout_session_with_course_id_in_redirect_urls(): void
    {
        $student = $this->makeStudent();
        $course = $this->makeCourse(attributes: ['price' => '49.99']);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/enroll", ['payment_flow' => 'web'])
            ->assertCreated()
            ->assertJsonPath('message', 'Continue to payment.')
            ->assertJsonPath('checkout_url', 'https://checkout.stripe.test/c/pay/cs_test_example');

        $params = $this->stripe->lastParams();

        $this->assertSame(
            "https://frontend.example.test/payment/success?course_id={$course->id}&session_id={CHECKOUT_SESSION_ID}",
            $params['success_url']
        );
        $this->assertSame(
            "https://frontend.example.test/payment/cancel?course_id={$course->id}",
            $params['cancel_url']
        );
        $this->assertSame(4999, $params['line_items'][0]['price_data']['unit_amount']);

        $payment = Payment::firstOrFail();
        $this->assertSame(PaymentStatus::PROCESSING, $payment->status);
        $this->assertSame('cs_test_example', $payment->stripe_checkout_session_id);
        $this->assertSame('49.99', $payment->amount);
    }

    public function test_paid_course_fails_cleanly_without_stripe_secret(): void
    {
        config(['services.stripe.secret' => null]);

        $student = $this->makeStudent();
        $course = $this->makeCourse(attributes: ['price' => '49.99']);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/enroll", ['payment_flow' => 'web'])
            ->assertStatus(500)
            ->assertJsonPath('message', 'The operation failed. Please try again.');
    }

    public function test_enroll_requires_authentication(): void
    {
        $course = $this->makeCourse();

        $this->postJson("/api/student/courses/{$course->id}/enroll", ['payment_flow' => 'web'])
            ->assertUnauthorized();
    }

    public function test_enroll_is_forbidden_for_instructors(): void
    {
        $course = $this->makeCourse();

        $this->actingAs($this->makeInstructor(), 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/enroll", ['payment_flow' => 'web'])
            ->assertForbidden();
    }

    public function test_enroll_returns_404_for_missing_course(): void
    {
        $this->actingAs($this->makeStudent(), 'sanctum')
            ->postJson('/api/student/courses/999/enroll', ['payment_flow' => 'web'])
            ->assertNotFound();
    }

    public function test_enroll_validates_payment_flow(): void
    {
        $course = $this->makeCourse();

        $this->actingAs($this->makeStudent(), 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/enroll", ['payment_flow' => 'fax'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_flow']);
    }

    public function test_unpublished_course_cannot_be_enrolled(): void
    {
        $course = $this->makeCourse(attributes: ['status' => CourseStatus::DRAFT]);

        $this->actingAs($this->makeStudent(), 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/enroll", ['payment_flow' => 'web'])
            ->assertStatus(422);
    }
}
