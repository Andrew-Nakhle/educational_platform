<?php

namespace Tests\Feature;

use App\Enums\User\UserStatus;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.upload_disk' => 'local_uploads']);
        Storage::fake('local_uploads');

        $this->seed(RoleSeeder::class);
    }

    private function studentPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Sara',
            'father_name' => 'Ahmad',
            'last_name' => 'Khalil',
            'gender' => 'female',
            'birthday' => '2000-01-15',
            'phone' => '0999000111',
            'email' => 'sara@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ], $overrides);
    }

    private function createStudent(array $attributes = []): User
    {
        $user = User::create(array_merge([
            'email' => 'student@example.com',
            'phone' => '0999000222',
            'gender' => 'male',
            'birthday' => '1999-05-20',
            'password' => Hash::make('secret-password'),
            'status' => UserStatus::ACTIVE,
        ], $attributes));

        $user->assignRole('student');

        $user->studentProfile()->create([
            'first_name' => 'Omar',
            'father_name' => 'Ali',
            'last_name' => 'Hasan',
        ]);

        return $user;
    }

    public function test_student_can_register(): void
    {
        $response = $this->postJson('/api/auth/register', $this->studentPayload());

        $response->assertCreated()
            ->assertJsonPath('message', 'Registration successful')
            ->assertJsonPath('user.email', 'sara@example.com')
            ->assertJsonPath('user.role', 'student')
            ->assertJsonPath('user.profile.type', 'student')
            ->assertJsonPath('user.profile.avatar', null)
            ->assertJsonStructure(['token']);

        $user = User::where('email', 'sara@example.com')->firstOrFail();

        $this->assertTrue($user->hasRole('student'));
        $this->assertSame('Sara', $user->studentProfile->first_name);
    }

    public function test_student_can_register_with_avatar_on_upload_disk(): void
    {
        $response = $this->post('/api/auth/register', $this->studentPayload([
            'avatar' => UploadedFile::fake()->image('avatar.png'),
        ]));

        $response->assertCreated();

        $avatar = User::where('email', 'sara@example.com')
            ->firstOrFail()
            ->studentProfile
            ->avatar;

        $this->assertStringStartsWith('students/avatars/', $avatar);
        Storage::disk('local_uploads')->assertExists($avatar);
        $this->assertNotNull($response->json('user.profile.avatar'));
    }

    public function test_register_validates_input(): void
    {
        $this->post('/api/auth/register', [])
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonValidationErrors(['first_name', 'email', 'password', 'phone']);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        $this->createStudent(['email' => 'sara@example.com']);

        $this->postJson('/api/auth/register', $this->studentPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_user_can_login(): void
    {
        $this->createStudent();

        $this->postJson('/api/auth/login', [
            'email' => 'student@example.com',
            'password' => 'secret-password',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('user.email', 'student@example.com')
            ->assertJsonPath('user.role', 'student')
            ->assertJsonStructure(['token']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->createStudent();

        $this->postJson('/api/auth/login', [
            'email' => 'student@example.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', 'The email or password is incorrect.');
    }

    public function test_blocked_user_cannot_login(): void
    {
        $this->createStudent(['status' => UserStatus::BLOCKED]);

        $this->postJson('/api/auth/login', [
            'email' => 'student@example.com',
            'password' => 'secret-password',
        ])->assertForbidden();
    }

    public function test_login_token_gives_access_to_profile(): void
    {
        $this->createStudent();

        $token = $this->postJson('/api/auth/login', [
            'email' => 'student@example.com',
            'password' => 'secret-password',
        ])->json('token');

        $this->withToken($token)
            ->getJson('/api/auth/profile')
            ->assertOk()
            ->assertJsonPath('email', 'student@example.com');
    }
}
