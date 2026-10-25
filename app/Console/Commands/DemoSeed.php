<?php

namespace App\Console\Commands;

use App\Enums\Course\CourseStatus;
use App\Enums\Course\VideoStatus;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentStatus;
use App\Enums\User\UserStatus;
use App\Models\Category;
use App\Models\ContactMessage;
use App\Models\Course;
use App\Models\CourseReview;
use App\Models\Enrollment;
use App\Models\InstructorProfile;
use App\Models\InstructorReview;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Fills the database with fake demo data for testing the whole app end to
 * end: accounts for every role and status, categories, courses in every
 * status with sections and lessons, enrollments with progress, ratings, a
 * contact message and payments.
 *
 * - Refuses to run when APP_ENV=production unless DEMO_SEED_ALLOW=true.
 * - Idempotent: every row is found by a stable key (email, name, title, …)
 *   and updated, so running it again doesn't duplicate anything.
 * - The password of every demo account is DEMO_SEED_PASSWORD, or a random
 *   one generated for this run and printed once. None is hardcoded.
 * - No external call (no Stripe) and no storage call: lessons are "ready"
 *   with placeholder paths (no real video), thumbnails and avatars are empty.
 */
class DemoSeed extends Command
{
    protected $signature = 'demo:seed';

    protected $description = 'Fill the database with fake demo data (idempotent; refuses in production unless DEMO_SEED_ALLOW=true)';

    public function handle(): int
    {
        if (app()->environment('production') && ! config('demo.allow_in_production')) {
            $this->error('demo:seed refuses to run when APP_ENV=production. Set DEMO_SEED_ALLOW=true to allow it.');

            return self::FAILURE;
        }

        $configured = (string) config('demo.password');
        $generated = $configured === '';
        $password = $generated ? Str::password(16, symbols: false) : $configured;
        $hash = Hash::make($password);

        DB::transaction(function () use ($hash) {
            (new RoleSeeder)->run(); // the three roles (firstOrCreate)

            $users = $this->seedUsers($hash);
            $categories = $this->seedCategories();
            $courses = $this->seedCourses($users, $categories);
            $this->seedLearning($users, $courses);
            $this->seedContactAndPayments($users, $courses);
        });

        $this->info('Demo data is ready.');
        $this->table(['Role', 'Email', 'Status'], [
            ['admin', 'admin@example.test', 'active'],
            ['instructor', 'instructor1@example.test … instructor3@example.test', 'active (approved)'],
            ['instructor', 'instructor4@example.test', 'pending approval'],
            ['instructor', 'instructor5@example.test', 'rejected (inactive, with a reason)'],
            ['student', 'student1@example.test … student3@example.test', 'active'],
        ]);

        if ($generated) {
            // Printed once, here. It is not stored anywhere else.
            $this->warn("Password of every demo account (generated for this run): {$password}");
            $this->line('Set DEMO_SEED_PASSWORD to choose it yourself; it is then not printed.');
        } else {
            $this->line('Password of every demo account: the value of DEMO_SEED_PASSWORD.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, User>
     */
    private function seedUsers(string $hash): array
    {
        $users = [];

        $users['admin'] = $this->user('admin@example.test', '0990000001', 'male', 'admin', UserStatus::ACTIVE, $hash);

        $instructors = [
            1 => ['Rami', 'Adel', 'Fares', 'male', 'software_engineering', 'Backend developer, teaching Laravel for ten years.', UserStatus::ACTIVE, null],
            2 => ['Nour', 'Walid', 'Hamdan', 'female', 'mathematics', 'Structural engineer and part-time lecturer.', UserStatus::ACTIVE, null],
            3 => ['Hadi', 'Karim', 'Mansour', 'male', 'business', 'Founder of two small companies; teaches finance basics.', UserStatus::ACTIVE, null],
            4 => ['Lama', 'Sami', 'Khoury', 'female', 'design', 'Product designer, waiting for approval.', UserStatus::PENDING, null],
            5 => ['Omar', 'Tarek', 'Saeed', 'male', 'physics', 'Physics teacher.', UserStatus::INACTIVE, 'The uploaded certificate is not readable.'],
        ];

        foreach ($instructors as $n => [$first, $father, $last, $gender, $major, $bio, $status, $reason]) {
            $user = $this->user("instructor{$n}@example.test", '099000010'.$n, $gender, 'instructor', $status, $hash);
            $user->instructorProfile()->updateOrCreate(['user_id' => $user->id], [
                'first_name' => $first,
                'father_name' => $father,
                'last_name' => $last,
                'avatar' => null,
                'teaching_major' => $major,
                'bio' => $bio,
                'reject_reason' => $reason,
            ]);
            $users["instructor{$n}"] = $user->load('instructorProfile');
        }

        $students = [1 => ['Lina', 'Fadi', 'Haddad', 'female'], 2 => ['Maya', 'Nabil', 'Aziz', 'female'], 3 => ['Sami', 'Omar', 'Khalil', 'male']];

        foreach ($students as $n => [$first, $father, $last, $gender]) {
            $user = $this->user("student{$n}@example.test", '099000020'.$n, $gender, 'student', UserStatus::ACTIVE, $hash);
            $user->studentProfile()->updateOrCreate(['user_id' => $user->id], [
                'first_name' => $first,
                'father_name' => $father,
                'last_name' => $last,
                'avatar' => null,
            ]);
            $users["student{$n}"] = $user;
        }

        return $users;
    }

    // Found by email (all @example.test); updated on every run.
    private function user(string $email, string $phone, string $gender, string $role, UserStatus $status, string $hash): User
    {
        $user = User::updateOrCreate(['email' => $email], [
            'phone' => $phone,
            'gender' => $gender,
            'birthday' => '1995-06-15',
            'password' => $hash,
            'status' => $status,
        ]);
        $user->syncRoles([$role]);

        return $user;
    }

    /**
     * @return array<string, Category>
     */
    private function seedCategories(): array
    {
        $categories = [];
        foreach (['Programming', 'Civil Engineering', 'Business', 'Design'] as $name) {
            $categories[$name] = Category::firstOrCreate(['name' => $name]);
        }

        return $categories;
    }

    /**
     * @return array<string, Course>
     */
    private function seedCourses(array $users, array $categories): array
    {
        $definitions = [
            'Laravel Basics' => ['instructor1', 'Programming', '0.00', CourseStatus::PUBLISHED, 'English', 'easy', null],
            'Advanced Eloquent' => ['instructor1', 'Programming', '49.99', CourseStatus::PUBLISHED, 'English', 'hard', null],
            'Concrete Design 101' => ['instructor2', 'Civil Engineering', '29.00', CourseStatus::PUBLISHED, 'Arabic', 'medium', null],
            'Startup Finance' => ['instructor3', 'Business', '19.99', CourseStatus::PENDING_PUBLISH, 'English', 'easy', null],
            'UI Sketching' => ['instructor3', 'Design', '0.00', CourseStatus::DRAFT, 'Arabic', 'easy', null],
            'Bridge Loads' => ['instructor2', 'Civil Engineering', '39.00', CourseStatus::REJECTED, 'English', 'hard', 'The audio in section 2 is too quiet.'],
            'Old Surveying' => ['instructor2', 'Civil Engineering', '15.00', CourseStatus::ARCHIVED, 'Arabic', 'medium', null],
        ];

        $courses = [];
        foreach ($definitions as $title => [$instructor, $category, $price, $status, $language, $level, $reason]) {
            // Found by title AND the demo instructor, so a real course with the
            // same title is never touched.
            $course = Course::updateOrCreate([
                'title' => $title,
                'instructor_profile_id' => $users[$instructor]->instructorProfile->id,
            ], [
                'category_id' => $categories[$category]->id,
                'description' => "{$title}: a demo course with fake content for testing.",
                'price' => $price,
                // Empty: the API returns null and the frontend shows its default image.
                'thumbnail' => '',
                'language' => $language,
                'difficulty_level' => $level,
                'what_you_will_learn' => "The main ideas of {$title}\nPractice with small examples\nA short final project",
                'status' => $status,
                'reject_reason' => $reason,
            ]);

            foreach (['Getting started', 'Going further'] as $s => $sectionTitle) {
                $section = $course->sections()->updateOrCreate(['title' => $sectionTitle], ['sort_order' => $s + 1]);
                foreach ([1, 2] as $l) {
                    $seconds = 300 + 60 * ($s * 2 + $l);
                    $lesson = $section->lessons()->updateOrCreate(['title' => "Lesson {$l}: {$sectionTitle}"], [
                        'description' => 'A short demo lesson.',
                        'duration_seconds' => $seconds,
                        'sort_order' => $l,
                    ]);
                    // "ready" with placeholder paths: listed as ready, but there is no
                    // real video, so it can't be played (no storage call is made).
                    $lesson->video()->updateOrCreate(['lesson_id' => $lesson->id], [
                        'source_path' => "demo/lessons/{$lesson->id}/source.mp4",
                        'playlist_path' => "demo/lessons/{$lesson->id}/hls/master.m3u8",
                        'duration_seconds' => $seconds,
                        'status' => VideoStatus::READY,
                    ]);
                }
            }

            $courses[$title] = $course->load('sections.lessons');
        }

        return $courses;
    }

    private function seedLearning(array $users, array $courses): void
    {
        $enrollments = [
            'student1' => ['Laravel Basics', 'Advanced Eloquent'],
            'student2' => ['Laravel Basics', 'Concrete Design 101'],
            'student3' => ['Concrete Design 101'],
        ];
        foreach ($enrollments as $student => $titles) {
            foreach ($titles as $title) {
                Enrollment::firstOrCreate(
                    ['user_id' => $users[$student]->id, 'course_id' => $courses[$title]->id],
                    ['enrolled_at' => now()->subDays(7)]
                );
            }
        }

        // Progress: student1 finished the first section of Laravel Basics,
        // student2 one lesson of it.
        $lessons = fn (string $title) => $courses[$title]->sections->sortBy('sort_order')->flatMap(fn ($s) => $s->lessons->sortBy('sort_order'))->values();
        foreach ([['student1', 2], ['student2', 1]] as [$student, $count]) {
            foreach ($lessons('Laravel Basics')->take($count) as $lesson) {
                LessonProgress::firstOrCreate(
                    ['user_id' => $users[$student]->id, 'lesson_id' => $lesson->id],
                    ['completed_at' => now()->subDays(2)]
                );
            }
        }

        $reviews = [
            ['student1', 'Laravel Basics', 5, 'Clear and practical.'],
            ['student2', 'Laravel Basics', 4, 'Good pace, a bit fast in places.'],
            ['student2', 'Concrete Design 101', 5, 'Great examples.'],
        ];
        foreach ($reviews as [$student, $title, $rating, $comment]) {
            CourseReview::updateOrCreate(
                ['user_id' => $users[$student]->id, 'course_id' => $courses[$title]->id],
                ['rating' => $rating, 'comment' => $comment]
            );
        }

        InstructorReview::updateOrCreate(
            ['user_id' => $users['student1']->id, 'instructor_profile_id' => $users['instructor1']->instructorProfile->id],
            ['rating' => 5, 'comment' => 'Explains everything step by step.']
        );
    }

    private function seedContactAndPayments(array $users, array $courses): void
    {
        ContactMessage::firstOrCreate(
            ['email' => 'visitor@example.test', 'message' => 'Do you offer group discounts for teams?'],
            ['name' => 'Demo Visitor']
        );

        // Local records only: no Stripe ids, nothing is sent to Stripe.
        // One demo payment per student and course (found by both), so a
        // re-run doesn't add one even if its status changed meanwhile.
        Payment::updateOrCreate(
            ['user_id' => $users['student1']->id, 'course_id' => $courses['Advanced Eloquent']->id],
            ['status' => PaymentStatus::PAID, 'payment_method' => 'card', 'provider' => PaymentProvider::STRIPE, 'amount' => '49.99', 'currency' => 'USD', 'paid_at' => now()->subDays(7)]
        );
        // Pending on the archived course, so it never blocks buying a
        // published one (enroll would reuse a pending payment).
        Payment::updateOrCreate(
            ['user_id' => $users['student3']->id, 'course_id' => $courses['Old Surveying']->id],
            ['status' => PaymentStatus::PENDING, 'payment_method' => 'card', 'provider' => PaymentProvider::STRIPE, 'amount' => '15.00', 'currency' => 'USD', 'paid_at' => null]
        );
    }
}
