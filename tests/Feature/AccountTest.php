<?php

namespace Tests\Feature;

use App\Enums\Course\CourseStatus;
use App\Enums\User\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTestData();
    }

    private function studentProfilePayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Maya',
            'father_name' => 'Nabil',
            'last_name' => 'Aziz',
            'phone' => '0933111222',
            'gender' => 'female',
            'birthday' => '2001-02-03',
        ], $overrides);
    }

    private function instructorProfilePayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Omar',
            'father_name' => 'Tarek',
            'last_name' => 'Saeed',
            'phone' => '0933444555',
            'gender' => 'male',
            'birthday' => '1988-08-08',
            'teaching_major' => 'software_engineering',
            'bio' => 'Updated bio.',
        ], $overrides);
    }

    // --- change password -------------------------------------------------

    public function test_every_role_can_change_password(): void
    {
        foreach ([$this->makeStudent(), $this->makeInstructor(), $this->makeAdmin()] as $user) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/auth/change-password', [
                    'current_password' => self::PASSWORD,
                    'password' => 'new-password-123',
                    'password_confirmation' => 'new-password-123',
                ])
                ->assertOk()
                ->assertExactJson(['message' => 'Password changed successfully.']);

            $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
        }
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $user = $this->makeStudent();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/change-password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.current_password.0', 'The current password is incorrect.');

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    public function test_change_password_validates_input(): void
    {
        $this->actingAs($this->makeStudent(), 'sanctum')
            ->postJson('/api/auth/change-password', [
                'current_password' => self::PASSWORD,
                'password' => 'short',
                'password_confirmation' => 'different',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_change_password_requires_authentication(): void
    {
        $this->postJson('/api/auth/change-password', [])->assertUnauthorized();
        $this->putJson('/api/auth/change-password', [])->assertUnauthorized();
    }

    public function test_change_password_accepts_upstream_put_with_new_password(): void
    {
        $user = $this->makeStudent();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/auth/change-password', [
                'current_password' => self::PASSWORD,
                'new_password' => 'new-password-123',
                'new_password_confirmation' => 'new-password-123',
            ])
            ->assertOk()
            ->assertExactJson(['message' => 'Password changed successfully.']);

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_change_password_requires_a_new_password_field(): void
    {
        $this->actingAs($this->makeStudent(), 'sanctum')
            ->putJson('/api/auth/change-password', [
                'current_password' => self::PASSWORD,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['new_password', 'password']);
    }

    // --- profiles ---------------------------------------------------------

    public function test_student_profile_update_returns_user_like_auth_profile(): void
    {
        $student = $this->makeStudent();
        $this->actingAs($student, 'sanctum');

        $response = $this->putJson('/api/student/profile', $this->studentProfilePayload())
            ->assertOk()
            ->assertJsonPath('message', 'Student profile updated successfully.')
            ->assertJsonPath('data.profile.first_name', 'Maya')
            ->assertJsonPath('data.phone', '0933111222');

        $this->assertSame($response->json('user'), $response->json('data'));
        $this->assertSame(
            $this->getJson('/api/auth/profile')->json(),
            $response->json('data')
        );
    }

    public function test_student_profile_accepts_avatar_via_multipart_post_with_method_override(): void
    {
        $student = $this->makeStudent();
        $this->actingAs($student, 'sanctum');

        $this->post('/api/student/profile', $this->studentProfilePayload([
            '_method' => 'PUT',
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ]))->assertOk();

        $avatar = $student->fresh()->studentProfile->avatar;
        $this->assertStringStartsWith('students/avatars/', $avatar);
        Storage::disk('local_uploads')->assertExists($avatar);

        // Updating without a file keeps the current avatar.
        $this->putJson('/api/student/profile', $this->studentProfilePayload(['first_name' => 'Mira']))
            ->assertOk()
            ->assertJsonPath('data.profile.first_name', 'Mira');

        $this->assertSame($avatar, $student->fresh()->studentProfile->avatar);
    }

    public function test_student_profile_rejects_non_image_avatar(): void
    {
        $this->actingAs($this->makeStudent(), 'sanctum')
            ->post('/api/student/profile', $this->studentProfilePayload([
                '_method' => 'PUT',
                'avatar' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
            ]), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['avatar']);
    }

    public function test_instructor_profile_update_returns_user_like_auth_profile(): void
    {
        $instructor = $this->makeInstructor();
        $this->actingAs($instructor, 'sanctum');

        $response = $this->post('/api/instructor/profile', $this->instructorProfilePayload([
            '_method' => 'PUT',
            'avatar' => UploadedFile::fake()->image('teacher.png'),
        ]))
            ->assertOk()
            ->assertJsonPath('message', 'Instructor profile updated successfully.')
            ->assertJsonPath('data.profile.teaching_major', 'software_engineering')
            ->assertJsonPath('data.profile.bio', 'Updated bio.');

        $this->assertSame($response->json('user'), $response->json('data'));

        $avatar = $instructor->fresh()->instructorProfile->avatar;
        Storage::disk('local_uploads')->assertExists($avatar);
        $this->assertSame(
            $this->getJson('/api/auth/profile')->json(),
            $response->json('data')
        );
    }

    public function test_profile_updates_validate_and_check_roles(): void
    {
        $this->putJson('/api/student/profile', [])->assertUnauthorized();

        $this->actingAs($this->makeInstructor(), 'sanctum')
            ->putJson('/api/student/profile', $this->studentProfilePayload())
            ->assertForbidden();

        $this->actingAs($this->makeStudent(), 'sanctum')
            ->putJson('/api/student/profile', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'phone']);
    }

    // --- stats ---------------------------------------------------------------

    public function test_stats_are_public(): void
    {
        $this->makeStudent();
        $this->makeStudent();
        $this->makeStudent(['status' => UserStatus::DELETED]);
        $active = $this->makeInstructor();
        $this->makeInstructor(['status' => UserStatus::PENDING]);
        $this->makeAdmin();

        $published = $this->makeCourse($active);
        $section = $this->makeSection($published);
        $this->makeLesson($section);
        $this->makeLesson($section);

        $draft = $this->makeCourse($active, ['status' => CourseStatus::DRAFT]);
        $this->makeLesson($this->makeSection($draft));

        $this->getJson('/api/stats')
            ->assertOk()
            ->assertExactJson([
                'message' => 'Statistics retrieved successfully.',
                'data' => [
                    'students' => 2,
                    'courses' => 1,
                    'instructors' => 1,
                    'lessons' => 2,
                ],
            ]);
    }
}
