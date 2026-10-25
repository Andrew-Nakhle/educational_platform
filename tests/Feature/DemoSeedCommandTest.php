<?php

namespace Tests\Feature;

use App\Enums\Course\CourseStatus;
use App\Enums\Course\VideoStatus;
use App\Enums\Payment\PaymentStatus;
use App\Enums\User\UserStatus;
use App\Models\Category;
use App\Models\ContactMessage;
use App\Models\Course;
use App\Models\CourseReview;
use App\Models\Enrollment;
use App\Models\InstructorReview;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\LessonVideo;
use App\Models\Payment;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTestData;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

class DemoSeedCommandTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTestData();
        Http::preventStrayRequests();
        $this->stripe = FakeStripeHttpClient::install();
        config(['demo.password' => null, 'demo.allow_in_production' => false]);
    }

    protected function tearDown(): void
    {
        FakeStripeHttpClient::uninstall();

        parent::tearDown();
    }

    private function counts(): array
    {
        return collect([
            User::class, Category::class, Course::class, Section::class, Lesson::class, LessonVideo::class,
            Enrollment::class, LessonProgress::class, CourseReview::class, InstructorReview::class,
            ContactMessage::class, Payment::class,
        ])->mapWithKeys(fn ($model) => [class_basename($model) => $model::count()])->all();
    }

    public function test_refuses_in_production_without_the_flag(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('demo:seed')
            ->expectsOutputToContain('refuses to run when APP_ENV=production')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_runs_in_production_with_the_flag(): void
    {
        $this->app['env'] = 'production';
        config(['demo.allow_in_production' => true, 'demo.password' => 'demo-pass-from-env']);

        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame(1, User::where('email', 'admin@example.test')->count());
    }

    public function test_creates_every_role_and_status(): void
    {
        config(['demo.password' => 'demo-pass-from-env']);
        $this->artisan('demo:seed')->assertSuccessful();

        $admin = User::where('email', 'admin@example.test')->firstOrFail();
        $this->assertTrue($admin->hasRole('admin'));

        foreach ([1, 2, 3] as $n) {
            $instructor = User::where('email', "instructor{$n}@example.test")->firstOrFail();
            $this->assertTrue($instructor->hasRole('instructor'));
            $this->assertSame(UserStatus::ACTIVE, $instructor->status);
            $this->assertNull($instructor->instructorProfile->reject_reason);
        }
        $this->assertSame(UserStatus::PENDING, User::where('email', 'instructor4@example.test')->value('status'));
        $rejected = User::where('email', 'instructor5@example.test')->firstOrFail();
        $this->assertSame(UserStatus::INACTIVE, $rejected->status);
        $this->assertNotEmpty($rejected->instructorProfile->reject_reason);

        foreach ([1, 2, 3] as $n) {
            $student = User::where('email', "student{$n}@example.test")->firstOrFail();
            $this->assertTrue($student->hasRole('student'));
            $this->assertNotNull($student->studentProfile);
        }

        // Every demo email is @example.test, and the password is the configured one.
        User::all()->each(function (User $user) {
            $this->assertStringEndsWith('@example.test', $user->email);
            $this->assertTrue(Hash::check('demo-pass-from-env', $user->password));
        });
        $this->assertStringEndsWith('@example.test', ContactMessage::query()->value('email'));

        $this->assertSame(4, Category::count());
        $statuses = Course::all()->groupBy(fn (Course $c) => $c->status->value)->map->count()->all();
        $this->assertSame(3, $statuses['published']);
        foreach (['pending_publish', 'draft', 'rejected', 'archived'] as $status) {
            $this->assertSame(1, $statuses[$status] ?? 0, $status);
        }
        $this->assertNotEmpty(Course::where('status', CourseStatus::REJECTED)->value('reject_reason'));
        $this->assertTrue(Course::where('price', 0)->exists());
        $this->assertTrue(Course::where('price', '>', 0)->exists());

        $this->assertTrue(Course::all()->every(fn (Course $c) => $c->sections()->count() > 0));
        $this->assertSame(Lesson::count(), LessonVideo::where('status', VideoStatus::READY)->count());

        $this->assertGreaterThan(0, Enrollment::count());
        $this->assertGreaterThan(0, LessonProgress::count());
        $this->assertTrue(CourseReview::whereNotNull('comment')->exists());
        $this->assertSame(1, InstructorReview::count());
        $this->assertSame(1, ContactMessage::count());
        $this->assertSame(1, Payment::where('status', PaymentStatus::PAID)->count());
        $this->assertSame(1, Payment::where('status', PaymentStatus::PENDING)->count());
    }

    public function test_is_idempotent(): void
    {
        config(['demo.password' => 'demo-pass-from-env']);
        $this->artisan('demo:seed')->assertSuccessful();
        $first = $this->counts();

        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame($first, $this->counts());
        $this->assertSame(1, User::where('email', 'student1@example.test')->count());
        $this->assertSame(1, User::where('email', 'student1@example.test')->firstOrFail()->roles()->count());
    }

    public function test_leaves_real_data_alone(): void
    {
        // A real course with the same title as a demo one, and a real user.
        $real = $this->makeCourse($this->makeInstructor(['email' => 'teacher@example.test']), ['title' => 'Laravel Basics', 'price' => '99.00']);

        config(['demo.password' => 'demo-pass-from-env']);
        $this->artisan('demo:seed')->assertSuccessful();

        $real->refresh();
        $this->assertSame('99.00', $real->price);
        $this->assertSame('teacher@example.test', $real->instructorProfile->user->email);
        $this->assertSame(2, Course::where('title', 'Laravel Basics')->count());
    }

    public function test_never_prints_the_password_when_it_is_configured(): void
    {
        config(['demo.password' => 'demo-pass-from-env']);

        Artisan::call('demo:seed');
        $output = Artisan::output();

        $this->assertStringNotContainsString('demo-pass-from-env', $output);
        $this->assertStringContainsString('the value of DEMO_SEED_PASSWORD', $output);
    }

    public function test_prints_a_generated_password_once_and_it_works(): void
    {
        Artisan::call('demo:seed');
        $output = Artisan::output();

        $this->assertSame(1, preg_match('/generated for this run\): (\S+)/', $output, $match));
        $password = $match[1];
        $this->assertSame(1, substr_count($output, $password));
        $this->assertGreaterThanOrEqual(16, strlen($password));
        $this->assertTrue(Hash::check($password, User::where('email', 'admin@example.test')->value('password')));

        // A new run, a new password.
        Artisan::call('demo:seed');
        $this->assertStringNotContainsString($password, Artisan::output());
    }

    public function test_calls_no_external_service_and_no_storage(): void
    {
        config(['demo.password' => 'demo-pass-from-env']);
        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame([], $this->stripe->requests);
        $this->assertSame([], Storage::disk('local_uploads')->allFiles());
    }

    public function test_the_seeded_accounts_can_log_in(): void
    {
        config(['demo.password' => 'demo-pass-from-env']);
        $this->artisan('demo:seed')->assertSuccessful();

        foreach (['admin@example.test', 'instructor1@example.test', 'student1@example.test'] as $email) {
            $this->app['auth']->forgetGuards();
            $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'demo-pass-from-env'])->assertOk();
        }

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => 'instructor4@example.test', 'password' => 'demo-pass-from-env'])
            ->assertForbidden(); // pending approval

        // The public catalog shows the three published courses.
        $this->getJson('/api/student/courses')->assertOk()->assertJsonCount(3, 'data');
    }
}
