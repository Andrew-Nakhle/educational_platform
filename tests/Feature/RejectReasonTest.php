<?php

namespace Tests\Feature;

use App\Enums\Course\CourseStatus;
use App\Enums\User\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class RejectReasonTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTestData();
    }

    public function test_course_reject_reason_is_stored_and_shown_to_the_owner_and_in_admin_details(): void
    {
        $instructor = $this->makeInstructor();
        $course = $this->makeCourse($instructor, ['status' => CourseStatus::PENDING_PUBLISH]);

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->patchJson("/api/admin/courses/{$course->id}/reject", ['reason' => 'Audio is too quiet.'])
            ->assertOk()
            ->assertExactJson([
                'message' => 'Course rejected successfully.',
                'status' => 'rejected',
            ]);

        $this->assertSame('Audio is too quiet.', $course->fresh()->reject_reason);

        // Admin course details include it; the admin list does not.
        $this->getJson("/api/admin/courses/{$course->id}")
            ->assertJsonPath('course.reject_reason', 'Audio is too quiet.');
        $this->getJson('/api/admin/courses')->assertJsonMissingPath('courses.0.reject_reason');

        $this->actingAs($instructor, 'sanctum');

        $this->getJson('/api/instructor/courses')
            ->assertJsonPath('courses.0.reject_reason', 'Audio is too quiet.');
        $this->getJson('/api/instructor/courses?page=1')
            ->assertJsonPath('data.0.reject_reason', 'Audio is too quiet.');
        $this->getJson("/api/instructor/courses/{$course->id}")
            ->assertJsonPath('course.reject_reason', 'Audio is too quiet.');
    }

    public function test_course_reject_reason_is_optional_and_validated(): void
    {
        $admin = $this->makeAdmin();
        $instructor = $this->makeInstructor();
        $course = $this->makeCourse($instructor, ['status' => CourseStatus::PENDING_PUBLISH]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/courses/{$course->id}/reject", ['reason' => str_repeat('a', 501)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->assertSame(CourseStatus::PENDING_PUBLISH, $course->fresh()->status);

        $this->patchJson("/api/admin/courses/{$course->id}/reject", ['reason' => str_repeat('a', 500)])
            ->assertOk();

        $other = $this->makeCourse($instructor, ['status' => CourseStatus::PENDING_PUBLISH]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/courses/{$other->id}/reject")
            ->assertOk()
            ->assertExactJson(['message' => 'Course rejected successfully.', 'status' => 'rejected']);

        $this->actingAs($instructor, 'sanctum')
            ->getJson("/api/instructor/courses/{$other->id}")
            ->assertJsonPath('course.reject_reason', null);
    }

    public function test_approving_a_course_clears_an_old_reject_reason(): void
    {
        $course = $this->giveLesson($this->makeCourse(attributes: [
            'status' => CourseStatus::PENDING_PUBLISH,
            'reject_reason' => 'Old reason.',
        ]));

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->patchJson("/api/admin/courses/{$course->id}/approve")
            ->assertOk()
            ->assertExactJson(['message' => 'Course approved and published successfully.', 'status' => 'published']);

        $this->assertNull($course->fresh()->reject_reason);
    }

    public function test_instructor_reject_reason_is_shown_in_login_and_profile(): void
    {
        $applicant = $this->makeInstructor(['status' => UserStatus::PENDING]);

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->patchJson("/api/admin/instructor-requests/{$applicant->id}/reject", ['reason' => 'Certificate is not readable.'])
            ->assertOk()
            ->assertExactJson(['message' => 'Instructor application rejected successfully.']);

        $this->assertSame(UserStatus::INACTIVE, $applicant->fresh()->status);
        $this->assertSame('Certificate is not readable.', $applicant->instructorProfile->fresh()->reject_reason);

        // Log in as a fresh client (actingAs switched the default guard).
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        $login = $this->postJson('/api/auth/login', [
            'email' => $applicant->email,
            'password' => self::PASSWORD,
        ])
            ->assertOk()
            ->assertJsonPath('user.status', 'inactive')
            ->assertJsonPath('user.reject_reason', 'Certificate is not readable.');

        $this->app['auth']->forgetGuards();

        $this->withToken($login->json('token'))
            ->getJson('/api/auth/profile')
            ->assertOk()
            ->assertJsonPath('status', 'inactive')
            ->assertJsonPath('reject_reason', 'Certificate is not readable.');
    }

    public function test_instructor_reject_reason_is_optional_validated_and_cleared(): void
    {
        $admin = $this->makeAdmin();
        $applicant = $this->makeInstructor(['status' => UserStatus::PENDING]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/instructor-requests/{$applicant->id}/reject", ['reason' => str_repeat('a', 501)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->assertSame(UserStatus::PENDING, $applicant->fresh()->status);

        $this->patchJson("/api/admin/instructor-requests/{$applicant->id}/reject", ['reason' => 'Missing bio.'])
            ->assertOk();

        // Re-activating the account clears the reason.
        $this->patchJson("/api/admin/users/{$applicant->id}/status", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('reject_reason', null);

        $this->assertNull($applicant->instructorProfile->fresh()->reject_reason);

        // Approving clears it too, and the response user has the key.
        $other = $this->makeInstructor(['status' => UserStatus::PENDING]);
        $other->instructorProfile->update(['reject_reason' => 'Old reason.']);

        $this->patchJson("/api/admin/instructor-requests/{$other->id}/approve")
            ->assertOk()
            ->assertJsonPath('instructor.reject_reason', null);
    }

    public function test_students_and_admins_have_no_reject_reason_key(): void
    {
        foreach ([$this->makeStudent(), $this->makeAdmin()] as $user) {
            $this->app['auth']->forgetGuards();

            $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
                ->assertOk()
                ->assertJsonMissingPath('user.reject_reason');
        }

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/auth/login', ['email' => $this->makeInstructor()->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('user.reject_reason', null);
    }
}
