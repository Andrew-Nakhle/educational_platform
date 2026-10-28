<?php

namespace Tests\Feature;

use App\Enums\Course\CourseStatus;
use App\Enums\Instructor\TeachingMajor;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentStatus;
use App\Enums\User\UserStatus;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * Admin payments and users lists, admin course details, the public
 * teaching majors list and my_rating in my-courses.
 */
class AdminListsTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTestData();
    }

    private function makePayment(User $student, array $attributes = []): Payment
    {
        return $student->payments()->create(array_merge([
            'course_id' => $this->makeCourse(attributes: ['price' => '49.99'])->id,
            'payment_method' => 'card',
            'provider' => PaymentProvider::STRIPE,
            'amount' => '49.99',
            'currency' => 'USD',
            'status' => PaymentStatus::PAID,
            'provider_payment_id' => 'pi_fake_123',
            'stripe_checkout_session_id' => 'cs_test_fake_123',
            'paid_at' => now(),
        ], $attributes));
    }

    // --- Payments ---------------------------------------------------------------

    public function test_admin_lists_payments_newest_first_with_safe_fields(): void
    {
        $student = $this->makeStudent([], ['first_name' => 'Lina', 'last_name' => 'Haddad']);

        Carbon::setTestNow('2026-10-20 10:00:00');
        $old = $this->makePayment($student, ['status' => PaymentStatus::FAILED, 'paid_at' => null]);
        Carbon::setTestNow('2026-10-21 10:00:00');
        $new = $this->makePayment($student);
        Carbon::setTestNow();

        $response = $this->actingAs($this->makeAdmin(), 'sanctum')
            ->getJson('/api/admin/payments')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('meta')
            ->assertJsonMissingPath('links')
            ->assertJsonPath('data.0.id', $new->id)
            ->assertJsonPath('data.1.id', $old->id)
            ->assertJsonPath('data.0.student', ['id' => $student->id, 'name' => 'Lina Haddad'])
            ->assertJsonPath('data.0.course.id', $new->course_id)
            ->assertJsonPath('data.0.course.title', 'Laravel Basics')
            ->assertJsonPath('data.0.amount', '49.99')
            ->assertJsonPath('data.0.currency', 'USD')
            ->assertJsonPath('data.0.status', 'paid')
            ->assertJsonPath('data.0.provider', 'stripe')
            ->assertJsonPath('data.0.provider_payment_id', 'pi_fake_123')
            ->assertJsonPath('data.0.created_at', '2026-10-21T10:00:00.000000Z')
            ->assertJsonPath('data.1.status', 'failed')
            ->assertJsonPath('data.1.paid_at', null);

        $this->assertSame([
            'id', 'student', 'course', 'amount', 'currency', 'status', 'provider',
            'payment_method', 'provider_payment_id', 'stripe_checkout_session_id', 'paid_at', 'created_at',
        ], array_keys($response->json('data.0')));

        $body = $response->getContent();
        foreach (['client_secret', 'checkout_url', 'secret', 'email', 'password'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    public function test_payments_are_paginated_with_page(): void
    {
        $student = $this->makeStudent();
        foreach (range(1, 3) as $i) {
            $this->makePayment($student);
        }

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->getJson('/api/admin/payments?page=2&per_page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta']);

        $this->getJson('/api/admin/payments?page=1&per_page=51')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/admin/payments?page=0')->assertStatus(422)->assertJsonValidationErrors(['page']);
    }

    public function test_payments_survive_deleted_student_or_course(): void
    {
        $payment = $this->makePayment($this->makeStudent());
        $payment->update(['user_id' => null, 'course_id' => null]);

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->getJson('/api/admin/payments')
            ->assertOk()
            ->assertJsonPath('data.0.student', null)
            ->assertJsonPath('data.0.course', null);
    }

    public function test_payments_are_admin_only(): void
    {
        $this->getJson('/api/admin/payments')->assertUnauthorized();

        foreach ([$this->makeStudent(), $this->makeInstructor()] as $user) {
            $this->actingAs($user, 'sanctum')->getJson('/api/admin/payments')->assertForbidden();
        }
    }

    // --- Users ----------------------------------------------------------------------

    public function test_users_list_without_page_is_unchanged(): void
    {
        $admin = $this->makeAdmin();
        $this->makeStudent();
        $this->makeInstructor();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->assertSame(['data'], array_keys($response->json()));
    }

    public function test_users_list_is_paginated_and_filtered(): void
    {
        $admin = $this->makeAdmin();
        $students = [$this->makeStudent(), $this->makeStudent(), $this->makeStudent(['status' => UserStatus::BLOCKED])];
        $this->makeInstructor();

        $this->actingAs($admin, 'sanctum');

        $this->getJson('/api/admin/users?page=1&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonStructure(['data', 'links', 'meta']);

        $this->getJson('/api/admin/users?role=student')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->getJson('/api/admin/users?role=student&status=blocked')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $students[2]->id);

        $response = $this->getJson('/api/admin/users?role=student&status=active&page=1&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.role', 'student');

        // Filters are kept in the pagination links.
        $this->assertStringContainsString('role=student', $response->json('links.next'));

        $this->getJson('/api/admin/users?role=teacher')->assertStatus(422)->assertJsonValidationErrors(['role']);
        $this->getJson('/api/admin/users?status=gone')->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->getJson('/api/admin/users?page=1&per_page=0')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
    }

    // --- Admin course details ------------------------------------------------------------

    public function test_admin_course_details_include_reject_reason(): void
    {
        $rejected = $this->makeCourse(attributes: ['status' => CourseStatus::REJECTED, 'reject_reason' => 'Add captions.']);
        $published = $this->makeCourse();

        $this->actingAs($this->makeAdmin(), 'sanctum');

        $this->getJson("/api/admin/courses/{$rejected->id}")
            ->assertOk()
            ->assertJsonPath('course.reject_reason', 'Add captions.');

        $this->getJson("/api/admin/courses/{$published->id}")
            ->assertOk()
            ->assertJsonPath('course.reject_reason', null);
    }

    // --- Teaching majors -----------------------------------------------------------------

    public function test_teaching_majors_are_public_and_match_the_validation_rule(): void
    {
        $response = $this->getJson('/api/teaching-majors')
            ->assertOk()
            ->assertExactJson(['data' => TeachingMajor::values()]);

        $this->assertContains('computer_science', $response->json('data'));

        // Every listed value is accepted, anything else is rejected.
        $instructor = $this->makeInstructor();
        $this->actingAs($instructor, 'sanctum');

        $profile = [
            'first_name' => 'Omar', 'father_name' => 'Tarek', 'last_name' => 'Saeed',
            'phone' => $instructor->phone, 'gender' => 'male', 'birthday' => '1988-08-08',
        ];

        foreach ($response->json('data') as $major) {
            $this->putJson('/api/instructor/profile', $profile + ['teaching_major' => $major])->assertOk();
        }

        $this->putJson('/api/instructor/profile', $profile + ['teaching_major' => 'astrology'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['teaching_major']);
    }

    // --- my-courses my_rating --------------------------------------------------------------

    public function test_my_courses_include_my_rating(): void
    {
        $student = $this->makeStudent();
        $rated = $this->makeCourse(attributes: ['title' => 'Rated']);
        $unrated = $this->makeCourse(attributes: ['title' => 'Unrated']);
        $this->enroll($student, $rated);
        $this->enroll($student, $unrated);

        $rated->reviews()->create(['user_id' => $student->id, 'rating' => 4]);
        // Another student's rating is not mine.
        $unrated->reviews()->create(['user_id' => $this->makeStudent()->id, 'rating' => 2]);

        $courses = collect(
            $this->actingAs($student, 'sanctum')
                ->getJson('/api/student/my-courses')
                ->assertOk()
                ->json('courses')
        )->keyBy('title');

        $this->assertSame(4, $courses['Rated']['my_rating']);
        $this->assertArrayHasKey('my_rating', $courses['Unrated']);
        $this->assertNull($courses['Unrated']['my_rating']);
    }
}
