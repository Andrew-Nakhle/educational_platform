<?php

namespace Tests\Feature;

use App\Enums\Course\CourseStatus;
use App\Enums\User\UserStatus;
use App\Models\InstructorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * Rejecting an instructor application without a reason stores the default
 * reason, so the account is still a rejected application and can re-apply.
 * An account an admin only deactivated keeps its email taken.
 */
class RejectWithoutReasonTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTestData();
    }

    // A fresh client after acting as the admin (auth:sanctum switched the default guard).
    private function asGuest(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
    }

    private function signUp(string $email, array $overrides = [])
    {
        return $this->postJson('/api/auth/register-instructor', array_merge([
            'first_name' => 'Nour',
            'father_name' => 'Walid',
            'last_name' => 'Hamdan',
            'gender' => 'female',
            'birthday' => '1992-04-12',
            'phone' => '0911222333',
            'email' => $email,
            'password' => 'another-password',
            'password_confirmation' => 'another-password',
            'teaching_major' => 'data_science',
        ], $overrides));
    }

    public function test_the_default_reason_is_stored_when_none_is_given(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'sanctum');

        foreach ([[], ['reason' => null], ['reason' => ''], ['reason' => '   ']] as $body) {
            $applicant = $this->makeInstructor(['status' => UserStatus::PENDING]);

            $this->patchJson("/api/admin/instructor-requests/{$applicant->id}/reject", $body)
                ->assertOk()
                ->assertExactJson(['message' => 'Instructor application rejected successfully.']);

            $this->assertSame(UserStatus::INACTIVE, $applicant->fresh()->status);
            $this->assertSame(InstructorProfile::DEFAULT_REJECT_REASON, $applicant->instructorProfile->fresh()->reject_reason);
        }

        $this->assertSame('Rejected by admin', InstructorProfile::DEFAULT_REJECT_REASON);
    }

    public function test_a_given_reason_is_kept(): void
    {
        $applicant = $this->makeInstructor(['status' => UserStatus::PENDING]);

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->patchJson("/api/admin/instructor-requests/{$applicant->id}/reject", ['reason' => 'The certificate is not readable.'])
            ->assertOk();

        $this->assertSame('The certificate is not readable.', $applicant->instructorProfile->fresh()->reject_reason);
    }

    public function test_the_rejected_instructor_sees_the_default_reason(): void
    {
        $applicant = $this->makeInstructor(['status' => UserStatus::PENDING]);

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->patchJson("/api/admin/instructor-requests/{$applicant->id}/reject")
            ->assertOk();
        $this->asGuest();

        $this->postJson('/api/auth/login', ['email' => $applicant->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('user.status', 'inactive')
            ->assertJsonPath('user.reject_reason', 'Rejected by admin');
    }

    public function test_rejected_without_a_reason_can_reapply_with_the_same_email(): void
    {
        $applicant = $this->makeInstructor(['email' => 'applicant@example.test', 'status' => UserStatus::PENDING]);

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->patchJson("/api/admin/instructor-requests/{$applicant->id}/reject")
            ->assertOk();
        $this->asGuest();

        $this->signUp('applicant@example.test')
            ->assertCreated()
            ->assertJsonPath('message', 'Your instructor registration is pending admin approval.')
            ->assertJsonPath('instructor.id', $applicant->id)
            ->assertJsonPath('instructor.status', 'pending')
            ->assertJsonPath('instructor.reject_reason', null);

        $this->assertSame(1, User::where('email', 'applicant@example.test')->count());
        $this->assertSame(UserStatus::PENDING, $applicant->fresh()->status);
        $this->assertNull($applicant->instructorProfile->fresh()->reject_reason);
    }

    public function test_a_deactivated_account_keeps_its_email_taken(): void
    {
        // Deactivated by an admin, never a rejected application: no reason.
        $instructor = $this->makeInstructor(['email' => 'deactivated@example.test']);

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->patchJson("/api/admin/users/{$instructor->id}/status", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('status', 'inactive')
            ->assertJsonPath('reject_reason', null);
        $this->asGuest();

        $this->signUp('deactivated@example.test')
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'The email has already been taken.');

        $this->assertSame(UserStatus::INACTIVE, $instructor->fresh()->status);
        $this->assertNull($instructor->instructorProfile->fresh()->reject_reason);
    }

    public function test_course_rejection_without_a_reason_is_unchanged(): void
    {
        $course = $this->makeCourse(attributes: ['status' => CourseStatus::PENDING_PUBLISH]);

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->patchJson("/api/admin/courses/{$course->id}/reject")
            ->assertOk();

        $this->assertNull($course->fresh()->reject_reason);
    }
}
