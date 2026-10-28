<?php

namespace Tests\Feature;

use App\Enums\User\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class InstructorReapplyTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTestData();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Nour',
            'father_name' => 'Walid',
            'last_name' => 'Hamdan',
            'gender' => 'female',
            'birthday' => '1992-04-12',
            'phone' => '0911222333',
            'email' => 'applicant@example.test',
            'password' => 'another-password',
            'password_confirmation' => 'another-password',
            'teaching_major' => 'data_science',
            'bio' => 'Re-applying with a clearer certificate.',
        ], $overrides);
    }

    private function makeRejectedInstructor(?string $reason = 'The certificate is not readable.'): User
    {
        $user = $this->makeInstructor(
            ['email' => 'applicant@example.test', 'status' => UserStatus::INACTIVE],
            ['reject_reason' => $reason, 'avatar' => 'instructors/avatars/old.png']
        );

        $user->instructorProfile->certificates()->create(['certification' => 'instructors/certificates/old.png']);

        Storage::disk('local_uploads')->put('instructors/avatars/old.png', 'old avatar');
        Storage::disk('local_uploads')->put('instructors/certificates/old.png', 'old certificate');

        return $user;
    }

    public function test_rejected_instructor_can_reapply_with_the_same_email(): void
    {
        $user = $this->makeRejectedInstructor();
        $user->createToken('auth_token');

        $response = $this->post('/api/auth/register-instructor', $this->payload([
            'avatar' => UploadedFile::fake()->image('avatar.png'),
            'certifications' => [UploadedFile::fake()->image('certificate.png')],
        ]), ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('message', 'Your instructor registration is pending admin approval.')
            ->assertJsonPath('instructor.id', $user->id)
            ->assertJsonPath('instructor.email', 'applicant@example.test')
            ->assertJsonPath('instructor.status', 'pending')
            ->assertJsonPath('instructor.role', 'instructor')
            ->assertJsonPath('instructor.reject_reason', null)
            ->assertJsonPath('instructor.profile.first_name', 'Nour')
            ->assertJsonPath('instructor.profile.teaching_major', 'data_science')
            ->assertJsonCount(1, 'instructor.profile.certifications');

        // Same top-level shape as a first registration.
        $this->assertSame(['message', 'instructor'], array_keys($response->json()));

        $user->refresh()->load('instructorProfile.certificates');
        $this->assertSame(1, User::where('email', 'applicant@example.test')->count());
        $this->assertSame(UserStatus::PENDING, $user->status);
        $this->assertSame('0911222333', $user->phone);
        $this->assertTrue(password_verify('another-password', $user->password));
        $this->assertNull($user->instructorProfile->reject_reason);
        $this->assertSame('Nour', $user->instructorProfile->first_name);
        $this->assertSame(0, $user->tokens()->count());

        // New files replace the old ones.
        $disk = Storage::disk('local_uploads');
        $disk->assertMissing('instructors/avatars/old.png');
        $disk->assertMissing('instructors/certificates/old.png');
        $disk->assertExists($user->instructorProfile->avatar);
        $disk->assertExists($user->instructorProfile->certificates->first()->certification);

        // Back in the admin queue.
        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->getJson('/api/admin/instructor-requests')
            ->assertJsonPath('data.0.id', $user->id);
    }

    public function test_reapply_without_new_files_keeps_the_old_ones(): void
    {
        $user = $this->makeRejectedInstructor();

        $this->postJson('/api/auth/register-instructor', $this->payload())
            ->assertCreated();

        $profile = $user->instructorProfile->fresh('certificates');
        $this->assertSame('instructors/avatars/old.png', $profile->avatar);
        $this->assertSame(['instructors/certificates/old.png'], $profile->certificates->pluck('certification')->all());
        Storage::disk('local_uploads')->assertExists('instructors/avatars/old.png');
    }

    public function test_other_existing_emails_get_422_not_500(): void
    {
        $this->makeStudent(['email' => 'student@example.test']);
        $this->makeInstructor(['email' => 'active@example.test']);
        $this->makeInstructor(['email' => 'pending@example.test', 'status' => UserStatus::PENDING]);
        // Deactivated by an admin (no reject reason): not a rejected application.
        $this->makeInstructor(['email' => 'inactive@example.test', 'status' => UserStatus::INACTIVE]);
        $this->makeInstructor(['email' => 'blocked@example.test', 'status' => UserStatus::BLOCKED], ['reject_reason' => 'Spam.']);

        foreach (['student', 'active', 'pending', 'inactive', 'blocked'] as $name) {
            $this->postJson('/api/auth/register-instructor', $this->payload([
                'email' => "{$name}@example.test",
            ]))
                ->assertStatus(422)
                ->assertJsonPath('errors.email.0', 'The email has already been taken.');
        }

        $this->assertSame(UserStatus::PENDING, User::where('email', 'pending@example.test')->value('status'));
    }

    public function test_phone_used_by_someone_else_gets_422(): void
    {
        $this->makeRejectedInstructor();
        $this->makeStudent(['phone' => '0955000111']);

        $this->postJson('/api/auth/register-instructor', $this->payload(['phone' => '0955000111']))
            ->assertStatus(422)
            ->assertJsonPath('errors.phone.0', 'The phone has already been taken.');

        $this->postJson('/api/auth/register-instructor', $this->payload([
            'email' => 'new@example.test',
            'phone' => '0955000111',
        ]))->assertStatus(422)->assertJsonValidationErrors(['phone']);

        $this->assertSame(UserStatus::INACTIVE, User::where('email', 'applicant@example.test')->value('status'));
    }

    public function test_reapplying_keeps_the_own_phone(): void
    {
        $user = $this->makeRejectedInstructor();

        $this->postJson('/api/auth/register-instructor', $this->payload(['phone' => $user->phone]))
            ->assertCreated();
    }

    public function test_new_instructor_registration_is_unchanged(): void
    {
        // With an avatar here; InstructorAvatarOptionalTest covers sign-up without one.
        $this->post('/api/auth/register-instructor', $this->payload([
            'email' => 'fresh@example.test',
            'avatar' => UploadedFile::fake()->image('avatar.png'),
        ]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('instructor.status', 'pending')
            ->assertJsonPath('instructor.email', 'fresh@example.test');
    }
}
