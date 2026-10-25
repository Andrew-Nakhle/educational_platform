<?php

namespace Tests\Feature;

use App\Enums\User\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * instructor_profiles.avatar is nullable: sign-up works without a picture,
 * and every response that shows the avatar returns null for it.
 */
class InstructorAvatarOptionalTest extends TestCase
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
            'first_name' => 'Hadi',
            'father_name' => 'Karim',
            'last_name' => 'Mansour',
            'gender' => 'male',
            'birthday' => '1990-01-01',
            'phone' => '0944555666',
            'email' => 'hadi@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'teaching_major' => 'physics',
        ], $overrides);
    }

    public function test_the_avatar_column_is_nullable(): void
    {
        $column = collect(Schema::getColumns('instructor_profiles'))->firstWhere('name', 'avatar');

        $this->assertTrue($column['nullable']);
    }

    public function test_the_migration_rolls_back_and_forward_with_rows_without_an_avatar(): void
    {
        $instructor = $this->makeInstructor([], ['avatar' => null]);
        $migration = require database_path('migrations/2026_10_27_100000_make_instructor_profiles_avatar_nullable.php');
        $nullable = fn () => collect(Schema::getColumns('instructor_profiles'))->firstWhere('name', 'avatar')['nullable'];

        $migration->down();
        $this->assertFalse($nullable());
        $this->assertSame('', $instructor->instructorProfile->fresh()->avatar);

        $migration->up();
        $this->assertTrue($nullable());
        $this->assertSame('', $instructor->instructorProfile->fresh()->avatar); // kept as is
    }

    public function test_instructor_signs_up_without_an_avatar(): void
    {
        $this->postJson('/api/auth/register-instructor', $this->payload())
            ->assertCreated()
            ->assertJsonPath('message', 'Your instructor registration is pending admin approval.')
            ->assertJsonPath('instructor.status', 'pending')
            ->assertJsonPath('instructor.profile.type', 'instructor')
            ->assertJsonPath('instructor.profile.avatar', null);

        $user = User::where('email', 'hadi@example.test')->firstOrFail();
        $this->assertNull($user->instructorProfile->avatar);
        $this->assertTrue($user->hasRole('instructor'));
        Storage::disk('local_uploads')->assertDirectoryEmpty('instructors/avatars');
    }

    public function test_instructor_signs_up_with_an_avatar(): void
    {
        $this->post('/api/auth/register-instructor', $this->payload([
            'avatar' => UploadedFile::fake()->image('avatar.png'),
        ]), ['Accept' => 'application/json'])
            ->assertCreated();

        $path = User::where('email', 'hadi@example.test')->firstOrFail()->instructorProfile->avatar;
        $this->assertStringStartsWith('instructors/avatars/', $path);
        Storage::disk('local_uploads')->assertExists($path);
    }

    public function test_a_wrong_avatar_file_is_still_refused(): void
    {
        $this->post('/api/auth/register-instructor', $this->payload([
            'avatar' => UploadedFile::fake()->create('avatar.pdf', 10, 'application/pdf'),
        ]), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['avatar']);

        $this->assertDatabaseMissing('users', ['email' => 'hadi@example.test']);
    }

    public static function missingAvatars(): array
    {
        // '' is what the migration's down() leaves for rows without one.
        return ['null' => [null], 'empty string' => ['']];
    }

    #[DataProvider('missingAvatars')]
    public function test_every_response_returns_null_for_a_missing_avatar(?string $avatar): void
    {
        $instructor = $this->makeInstructor([], ['avatar' => $avatar]);
        $course = $this->makeCourse($instructor);
        $this->makeInstructor(['status' => UserStatus::PENDING], ['avatar' => $avatar]);

        // Public catalog: list and details.
        $this->getJson('/api/student/courses')->assertOk()->assertJsonPath('data.0.instructor.avatar', null);
        $this->getJson("/api/student/courses/{$course->id}")->assertOk()->assertJsonPath('data.instructor.avatar', null);

        // The instructor's own login.
        $this->postJson('/api/auth/login', ['email' => $instructor->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('user.profile.avatar', null);
        $this->app['auth']->forgetGuards();

        // Admin lists and details.
        $this->actingAs($this->makeAdmin(), 'sanctum');
        $this->getJson('/api/admin/users?role=instructor')->assertOk()->assertJsonPath('data.0.profile.avatar', null);
        $this->getJson("/api/admin/users/{$instructor->id}")->assertOk()->assertJsonPath('profile.avatar', null);
        $this->getJson('/api/admin/instructor-requests')->assertOk()->assertJsonPath('data.0.profile.avatar', null);
        $this->getJson("/api/admin/courses/{$course->id}")->assertOk()->assertJsonPath('course.instructor.avatar', null);
    }
}
