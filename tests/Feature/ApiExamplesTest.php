<?php

namespace Tests\Feature;

use App\Enums\Course\CourseStatus;
use App\Enums\User\UserStatus;
use App\Models\ContactMessage;
use App\Models\Course;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTestData;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * Runs the main API flows with fake data and records the real responses.
 *
 * Regenerate docs/API-EXAMPLES.md with:
 *   PowerShell: $env:API_EXAMPLES=1; php artisan test --filter=ApiExamplesTest; Remove-Item Env:API_EXAMPLES
 *   bash:       API_EXAMPLES=1 php artisan test --filter=ApiExamplesTest
 */
class ApiExamplesTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    private const REDACTED_KEYS = ['token', 'client_secret'];

    /** @var array<int, array<string, mixed>> */
    private array $examples = [];

    private FakeStripeHttpClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTestData();

        Carbon::setTestNow('2026-10-24 10:00:00');

        $sequence = 0;
        Str::createRandomStringsUsing(function (int $length) use (&$sequence) {
            return substr(str_pad('example'.++$sequence, $length, 'x'), 0, $length);
        });

        config([
            'app.url' => 'https://api.example.test',
            'app.frontend_url' => 'https://frontend.example.test',
            'services.stripe.secret' => 'sk_test_fake',
        ]);
        URL::forceRootUrl('https://api.example.test');
        URL::forceScheme('https');

        Queue::fake();
        $this->stripe = FakeStripeHttpClient::install();
    }

    protected function tearDown(): void
    {
        FakeStripeHttpClient::uninstall();
        Str::createRandomStringsNormally();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_capture_api_examples(): void
    {
        // --- Fake data -----------------------------------------------------
        $admin = $this->makeAdmin(['email' => 'admin@example.test', 'phone' => '0900000001']);
        $instructor = $this->makeInstructor(['email' => 'instructor@example.test', 'phone' => '0900000002']);
        $student = $this->makeStudent(['email' => 'student@example.test', 'phone' => '0900000003']);
        $otherStudent = $this->makeStudent(
            ['email' => 'student2@example.test', 'phone' => '0900000004'],
            ['first_name' => 'Rami', 'last_name' => 'Saleh']
        );

        $category = $this->makeCategory('Programming');

        $freeCourse = $this->makeCourse($instructor, ['title' => 'Laravel Basics', 'price' => 0]);
        $section = $this->makeSection($freeCourse, ['title' => 'Getting started']);
        $firstLesson = $this->makeLesson($section, ['title' => 'Installing Laravel', 'duration_seconds' => 420]);
        $this->makeLesson($section, ['title' => 'Routing', 'duration_seconds' => 610]);

        $paidCourse = $this->makeCourse($instructor, [
            'title' => 'Advanced Eloquent',
            'price' => '49.99',
            'difficulty_level' => 'hard',
        ]);

        $toReject = $this->makeCourse($instructor, [
            'title' => 'Draft Ideas',
            'status' => CourseStatus::PENDING_PUBLISH,
        ]);

        // --- Auth ----------------------------------------------------------
        $tokens = [];

        foreach (['admin' => $admin, 'instructor' => $instructor, 'student' => $student] as $role => $user) {
            $response = $this->record(
                "Login ({$role})",
                'POST',
                '/api/auth/login',
                ['email' => $user->email, 'password' => self::PASSWORD]
            )->assertOk();

            $tokens[$role] = $response->json('token');
        }

        $this->record('Current user profile (student)', 'GET', '/api/auth/profile', token: $tokens['student'])
            ->assertOk();

        // --- Instructor ----------------------------------------------------
        $created = $this->record(
            'Create a course (multipart)',
            'POST',
            '/api/instructor/courses',
            [
                'title' => 'Testing Laravel APIs',
                'description' => 'Feature tests for REST APIs.',
                'price' => '29.00',
                'category_id' => $category->id,
                'language' => 'English',
                'difficulty_level' => 'medium',
                'what_you_will_learn' => 'HTTP tests, factories and fakes.',
                'thumbnail' => UploadedFile::fake()->image('thumbnail.png'),
            ],
            $tokens['instructor'],
            multipart: true
        )->assertCreated();

        $newCourseId = $created->json('course.id');

        $this->record('List my courses (instructor)', 'GET', '/api/instructor/courses', token: $tokens['instructor'])
            ->assertOk();

        $this->record('Course by id (instructor)', 'GET', "/api/instructor/courses/{$newCourseId}", token: $tokens['instructor'])
            ->assertOk();

        $this->record(
            'Update a course (JSON; send multipart as POST with _method=PUT to change the thumbnail)',
            'PUT',
            "/api/instructor/courses/{$newCourseId}",
            ['title' => 'Testing Laravel APIs (2nd edition)', 'price' => '35.5'],
            $tokens['instructor']
        )->assertOk();

        $newSection = $this->record(
            'Create a section',
            'POST',
            "/api/instructor/courses/{$newCourseId}/sections",
            ['title' => 'HTTP tests'],
            $tokens['instructor']
        )->assertCreated();

        $this->record(
            'Create a lesson with a video (multipart; processing is queued)',
            'POST',
            "/api/instructor/sections/{$newSection->json('section.id')}/lessons",
            [
                'title' => 'Your first feature test',
                'description' => 'Write and run a feature test.',
                'video' => UploadedFile::fake()->create('lesson.mp4', 512, 'video/mp4'),
            ],
            $tokens['instructor'],
            multipart: true
        )->assertCreated();

        $this->record('Submit a course for review', 'POST', "/api/instructor/courses/{$newCourseId}/submit", token: $tokens['instructor'])
            ->assertOk();

        // --- Admin -----------------------------------------------------------
        $this->record('List users (admin)', 'GET', '/api/admin/users', token: $tokens['admin'])
            ->assertOk();

        $this->record('User by id (admin)', 'GET', "/api/admin/users/{$student->id}", token: $tokens['admin'])
            ->assertOk();

        $this->record(
            'Change a user status (admin)',
            'PATCH',
            "/api/admin/users/{$otherStudent->id}/status",
            ['status' => 'inactive'],
            $tokens['admin']
        )->assertOk();

        $this->record('List courses (admin)', 'GET', '/api/admin/courses?status=pending_publish', token: $tokens['admin'])
            ->assertOk();

        $this->record('Course by id (admin)', 'GET', "/api/admin/courses/{$freeCourse->id}", token: $tokens['admin'])
            ->assertOk();

        $this->record('Approve a course (admin)', 'PATCH', "/api/admin/courses/{$newCourseId}/approve", token: $tokens['admin'])
            ->assertOk();

        $this->record(
            'Reject a course with an optional reason (admin)',
            'PATCH',
            "/api/admin/courses/{$toReject->id}/reject",
            ['reason' => 'The audio in section 2 is too quiet.'],
            $tokens['admin']
        )->assertOk();

        $this->record(
            'Rejected course with reject_reason (instructor, own course)',
            'GET',
            "/api/instructor/courses/{$toReject->id}",
            token: $tokens['instructor']
        )->assertOk();

        // --- Student -----------------------------------------------------------
        $this->record('Course details (guest, no token)', 'GET', "/api/student/courses/{$freeCourse->id}")
            ->assertOk();

        $this->record(
            'Enroll in a FREE course',
            'POST',
            "/api/student/courses/{$freeCourse->id}/enroll",
            ['payment_flow' => 'web'],
            $tokens['student']
        )->assertCreated();

        $this->record(
            'Enroll in a PAID course (web, Stripe Checkout; Stripe is faked)',
            'POST',
            "/api/student/courses/{$paidCourse->id}/enroll",
            ['payment_flow' => 'web'],
            $tokens['student']
        )->assertCreated();

        $stripeParams = $this->stripe->lastParams();
        $this->examples[array_key_last($this->examples)]['note'] =
            "Redirect URLs sent to Stripe:\n"
            ."- success_url: `{$stripeParams['success_url']}`\n"
            ."- cancel_url: `{$stripeParams['cancel_url']}`";

        $this->record(
            'Mark a lesson as completed',
            'POST',
            "/api/student/lessons/{$firstLesson->id}/complete",
            token: $tokens['student']
        )->assertOk();

        $this->record('Course details (enrolled student)', 'GET', "/api/student/courses/{$freeCourse->id}", token: $tokens['student'])
            ->assertOk();

        $this->record('My courses', 'GET', '/api/student/my-courses', token: $tokens['student'])
            ->assertOk();

        $this->record(
            'Rate a course',
            'POST',
            "/api/student/courses/{$freeCourse->id}/rate",
            ['rating' => 5, 'comment' => 'Clear and practical.'],
            $tokens['student']
        )->assertCreated();

        $this->record(
            'Rate a course a second time (409, use PUT to change it)',
            'POST',
            "/api/student/courses/{$freeCourse->id}/rate",
            ['rating' => 3],
            $tokens['student']
        )->assertStatus(409);

        $this->record(
            'Update my course rating',
            'PUT',
            "/api/student/courses/{$freeCourse->id}/rate",
            ['rating' => 4, 'comment' => 'Clear, a bit fast in places.'],
            $tokens['student']
        )->assertOk();

        $this->record(
            'Update a rating that does not exist yet (404)',
            'PUT',
            "/api/student/courses/{$freeCourse->id}/rate-instructor",
            ['rating' => 5],
            $tokens['student']
        )->assertNotFound();

        $this->record(
            'Rate the instructor of a course',
            'POST',
            "/api/student/courses/{$freeCourse->id}/rate-instructor",
            ['rating' => 5, 'comment' => 'Explains well.'],
            $tokens['student']
        )->assertCreated();

        $this->record(
            'Update my instructor rating',
            'PUT',
            "/api/student/courses/{$freeCourse->id}/rate-instructor",
            ['rating' => 4, 'comment' => null],
            $tokens['student']
        )->assertOk();

        $this->record('Course ratings (guest, my_rating is null)', 'GET', "/api/courses/{$freeCourse->id}/ratings")
            ->assertOk();

        $this->record('Course ratings (student token, with my_rating)', 'GET', "/api/courses/{$freeCourse->id}/ratings", token: $tokens['student'])
            ->assertOk();

        $this->record(
            'Instructor ratings (student token, with my_rating)',
            'GET',
            "/api/instructors/{$freeCourse->instructor_profile_id}/ratings",
            token: $tokens['student']
        )->assertOk();

        // --- Profiles -------------------------------------------------------
        $this->record(
            'Update the student profile (JSON)',
            'PUT',
            '/api/student/profile',
            [
                'first_name' => 'Maya',
                'father_name' => 'Nabil',
                'last_name' => 'Aziz',
                'phone' => '0933111222',
                'gender' => 'female',
                'birthday' => '2001-02-03',
            ],
            $tokens['student']
        )->assertOk();

        $this->record(
            'Update the instructor profile (multipart POST with _method=PUT)',
            'POST',
            '/api/instructor/profile',
            [
                '_method' => 'PUT',
                'first_name' => 'Omar',
                'father_name' => 'Tarek',
                'last_name' => 'Saeed',
                'phone' => '0933444555',
                'gender' => 'male',
                'birthday' => '1988-08-08',
                'teaching_major' => 'software_engineering',
                'bio' => 'Backend developer and teacher.',
                'avatar' => UploadedFile::fake()->image('avatar.png'),
            ],
            $tokens['instructor'],
            multipart: true
        )->assertOk();

        // --- Password reset ---------------------------------------------------
        Notification::fake();

        $this->record('Forgot password (sends the reset mail)', 'POST', '/api/auth/forgot-password', ['email' => $student->email])
            ->assertOk();

        Notification::assertSentTo($student, ResetPasswordNotification::class, function ($notification) use ($student) {
            $url = $notification->toMail($student)->actionUrl;

            $this->examples[array_key_last($this->examples)]['note'] =
                'Link in the mail (token redacted): `'
                .str_replace($notification->token, '<token>', $url).'`';

            return true;
        });

        // --- Statistics and errors -----------------------------------------------
        $this->record('Platform statistics (public)', 'GET', '/api/stats')
            ->assertOk();

        $this->record('Admin statistics', 'GET', '/api/admin/stats', token: $tokens['admin'])
            ->assertOk();

        $this->record('Missing record (404 for any missing model)', 'GET', '/api/admin/users/999', token: $tokens['admin'])
            ->assertNotFound();

        // --- Paginated course lists (?page=) -----------------------------------------
        $this->record('Course catalog, paginated', 'GET', '/api/student/courses?page=1&per_page=2')
            ->assertOk();

        $this->record('My courses (instructor), paginated', 'GET', '/api/instructor/courses?page=1&per_page=2', token: $tokens['instructor'])
            ->assertOk();

        $this->record('List courses (admin), paginated', 'GET', '/api/admin/courses?page=2&per_page=2', token: $tokens['admin'])
            ->assertOk();

        // --- Instructor application rejected with a reason ---------------------------
        $applicant = $this->makeInstructor(
            ['email' => 'applicant@example.test', 'phone' => '0900000005', 'status' => UserStatus::PENDING],
            ['first_name' => 'Nour', 'last_name' => 'Hamdan']
        );

        $this->record(
            'Reject an instructor application with an optional reason (admin)',
            'PATCH',
            "/api/admin/instructor-requests/{$applicant->id}/reject",
            ['reason' => 'The uploaded certificate is not readable.'],
            $tokens['admin']
        )->assertOk();

        $this->record(
            'Login (rejected instructor, with reject_reason)',
            'POST',
            '/api/auth/login',
            ['email' => $applicant->email, 'password' => self::PASSWORD]
        )->assertOk();

        // --- Contact form ------------------------------------------------------------
        $this->record(
            'Send a contact message (public, 5 per minute)',
            'POST',
            '/api/contact',
            ['name' => 'Sara Khalil', 'email' => 'sara@example.test', 'message' => 'Do you offer group discounts?'],
        )->assertCreated();

        $this->record('Contact messages (admin)', 'GET', '/api/admin/contact-messages', token: $tokens['admin'])
            ->assertOk();

        $contactId = ContactMessage::query()->value('id');

        $this->record('Mark a contact message as read (admin)', 'PATCH', "/api/admin/contact-messages/{$contactId}/read", token: $tokens['admin'])
            ->assertOk();

        // --- Reorder (draft or rejected courses only) ---------------------------------
        $draftCourse = $this->makeCourse($instructor, ['title' => 'Docker for Laravel', 'status' => CourseStatus::DRAFT]);
        $draftSection = $this->makeSection($draftCourse, ['title' => 'Containers']);
        $this->makeLesson($draftSection, ['title' => 'Images and containers', 'duration_seconds' => 380]);
        $this->makeLesson($draftSection, ['title' => 'Compose', 'duration_seconds' => 450]);
        $secondSection = $this->makeSection($draftCourse, ['title' => 'Going further']);

        $this->record(
            'Reorder the sections of a course (instructor)',
            'PATCH',
            "/api/instructor/courses/{$draftCourse->id}/sections/reorder",
            ['ids' => [$secondSection->id, $draftSection->id]],
            $tokens['instructor']
        )->assertOk();

        $lessonIds = $draftSection->lessons()->orderBy('sort_order')->pluck('id')->all();

        $this->record(
            'Reorder the lessons of a section (instructor)',
            'PATCH',
            "/api/instructor/sections/{$draftSection->id}/lessons/reorder",
            ['ids' => array_reverse($lessonIds)],
            $tokens['instructor']
        )->assertOk();

        $this->record(
            'Reorder with ids that are not exactly the section lessons (422)',
            'PATCH',
            "/api/instructor/sections/{$draftSection->id}/lessons/reorder",
            ['ids' => [$lessonIds[0]]],
            $tokens['instructor']
        )->assertStatus(422);

        if (getenv('API_EXAMPLES')) {
            $this->writeMarkdown(base_path('docs/API-EXAMPLES.md'));
        }

        $this->assertNotEmpty($this->examples);
    }

    private function record(
        string $title,
        string $method,
        string $uri,
        array $data = [],
        ?string $token = null,
        bool $multipart = false
    ): TestResponse {
        // Each call is a fresh client: no cached user, no session left by
        // Auth::attempt() in login, no leftover headers.
        $this->app['session']->driver()->flush();
        $this->app['auth']->forgetGuards();
        // auth:sanctum switches the default guard (and its config value).
        $this->app['auth']->shouldUse('web');
        $this->flushHeaders();

        $headers = ['Accept' => 'application/json'];

        if ($token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $response = $multipart
            ? $this->call($method, $uri, $this->withoutFiles($data), [], $this->onlyFiles($data), $this->transformHeadersToServerVars($headers))
            : $this->json($method, $uri, $data, $headers);

        $this->examples[] = [
            'title' => $title,
            'method' => $method,
            'uri' => $uri,
            'auth' => $token !== null,
            'multipart' => $multipart,
            'request' => $this->describeRequest($data),
            'status' => $response->status(),
            'response' => $this->redact($response->json()),
        ];

        if (getenv('API_EXAMPLES_DEBUG') && $response->status() >= 400) {
            fwrite(STDERR, "\n{$method} {$uri} -> {$response->status()} ".$response->getContent()."\n");
        }

        return $response;
    }

    private function onlyFiles(array $data): array
    {
        return array_filter($data, fn ($value) => $value instanceof UploadedFile);
    }

    private function withoutFiles(array $data): array
    {
        return array_filter($data, fn ($value) => ! $value instanceof UploadedFile);
    }

    private function describeRequest(array $data): array
    {
        return array_map(
            fn ($value) => $value instanceof UploadedFile
                ? "<file: {$value->getClientOriginalName()}>"
                : $value,
            $data
        );
    }

    private function redact(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = in_array($key, self::REDACTED_KEYS, true) && is_string($item)
                ? "<{$key}>"
                : $this->redact($item);
        }

        return $value;
    }

    private function writeMarkdown(string $path): void
    {
        $json = fn ($value) => json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $lines = [
            '# API examples',
            '',
            'Real responses captured by `tests/Feature/ApiExamplesTest.php` running the',
            'application with fake data (`*@example.test` users, a faked upload disk,',
            'a faked Stripe HTTP client and a faked queue). Tokens and client secrets',
            'are replaced with placeholders; signed file URLs are fake.',
            '',
            'Regenerate (PowerShell):',
            '',
            '```powershell',
            '$env:API_EXAMPLES=1; php artisan test --filter=ApiExamplesTest; Remove-Item Env:API_EXAMPLES',
            '```',
            '',
            'All requests send `Accept: application/json`; authenticated requests send',
            '`Authorization: Bearer <token>` with the token returned by `POST /api/auth/login`.',
            'Multipart requests (file uploads) must be sent as `POST`; to update with a file',
            'use `POST` with a `_method=PUT` field.',
            '',
            '## Contents',
            '',
        ];

        foreach ($this->examples as $i => $example) {
            $lines[] = sprintf(
                '%d. [%s](#%s) `%s %s`',
                $i + 1,
                $example['title'],
                $this->anchor($i, $example['title']),
                $example['method'],
                strtok($example['uri'], '?')
            );
        }

        foreach ($this->examples as $i => $example) {
            $lines[] = '';
            $lines[] = sprintf('<a id="%s"></a>', $this->anchor($i, $example['title']));
            $lines[] = sprintf('## %d. %s', $i + 1, $example['title']);
            $lines[] = '';
            $lines[] = sprintf('`%s %s`', $example['method'], $example['uri']);
            $lines[] = '';
            $lines[] = '- Auth: '.($example['auth'] ? '`Authorization: Bearer <token>`' : 'none');

            if ($example['request']) {
                $lines[] = '- Body ('.($example['multipart'] ? 'multipart/form-data' : 'JSON').'):';
                $lines[] = '';
                $lines[] = '```json';
                $lines[] = $json($example['request']);
                $lines[] = '```';
            }

            $lines[] = '';
            $lines[] = "Response `{$example['status']}`:";
            $lines[] = '';
            $lines[] = '```json';
            $lines[] = $json($example['response']);
            $lines[] = '```';

            if (isset($example['note'])) {
                $lines[] = '';
                $lines[] = $example['note'];
            }
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, implode("\n", $lines)."\n");
    }

    private function anchor(int $index, string $title): string
    {
        return 'example-'.($index + 1).'-'.Str::slug(Str::before($title, '('));
    }
}
