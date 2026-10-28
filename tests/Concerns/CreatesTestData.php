<?php

namespace Tests\Concerns;

use App\Enums\Course\CourseStatus;
use App\Enums\Course\VideoStatus;
use App\Enums\User\UserStatus;
use App\Models\Category;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

trait CreatesTestData
{
    public const PASSWORD = 'secret-password';

    private int $userSequence = 0;

    protected function setUpTestData(): void
    {
        config(['filesystems.upload_disk' => 'local_uploads']);
        Storage::fake('local_uploads');

        $this->seed(RoleSeeder::class);
    }

    protected function makeUser(string $role, array $attributes = []): User
    {
        $this->userSequence++;

        $user = User::create(array_merge([
            'email' => "{$role}{$this->userSequence}@example.test",
            'phone' => '0999'.str_pad((string) $this->userSequence, 6, '0', STR_PAD_LEFT),
            'gender' => 'male',
            'birthday' => '1995-06-15',
            'password' => Hash::make(self::PASSWORD),
            'status' => UserStatus::ACTIVE,
        ], $attributes));

        $user->assignRole($role);

        return $user;
    }

    protected function makeStudent(array $attributes = [], array $profile = []): User
    {
        $user = $this->makeUser('student', $attributes);

        $user->studentProfile()->create(array_merge([
            'first_name' => 'Lina',
            'father_name' => 'Fadi',
            'last_name' => 'Haddad',
        ], $profile));

        return $user->load('studentProfile');
    }

    protected function makeInstructor(array $attributes = [], array $profile = []): User
    {
        $user = $this->makeUser('instructor', $attributes);

        $user->instructorProfile()->create(array_merge([
            'first_name' => 'Khaled',
            'father_name' => 'Samir',
            'last_name' => 'Nasser',
            'avatar' => 'instructors/avatars/example.png',
            'teaching_major' => 'computer_science',
            'bio' => 'Backend developer and teacher.',
        ], $profile));

        return $user->load('instructorProfile');
    }

    protected function makeAdmin(array $attributes = []): User
    {
        return $this->makeUser('admin', $attributes);
    }

    protected function makeCategory(string $name = 'Programming'): Category
    {
        return Category::firstOrCreate(['name' => $name]);
    }

    protected function makeCourse(?User $instructor = null, array $attributes = []): Course
    {
        $instructor ??= $this->makeInstructor();

        return Course::create(array_merge([
            'instructor_profile_id' => $instructor->instructorProfile->id,
            'category_id' => $this->makeCategory()->id,
            'title' => 'Laravel Basics',
            'description' => 'Build REST APIs with Laravel.',
            'price' => 0,
            'thumbnail' => 'courses/thumbnails/example.png',
            'language' => 'English',
            'difficulty_level' => 'easy',
            'what_you_will_learn' => 'Routing, Eloquent and testing.',
            'status' => CourseStatus::PUBLISHED,
        ], $attributes));
    }

    protected function makeSection(Course $course, array $attributes = []): Section
    {
        return $course->sections()->create(array_merge([
            'title' => 'Getting started',
            'sort_order' => $course->sections()->count() + 1,
        ], $attributes));
    }

    /**
     * One section with one lesson: a course needs it to be submitted for
     * publishing or approved.
     */
    protected function giveLesson(Course $course): Course
    {
        $this->makeLesson($this->makeSection($course));

        return $course;
    }

    protected function makeLesson(
        Section $section,
        array $attributes = [],
        ?VideoStatus $videoStatus = VideoStatus::READY
    ): Lesson {
        $lesson = $section->lessons()->create(array_merge([
            'title' => 'Lesson '.($section->lessons()->count() + 1),
            'description' => 'Lesson description.',
            'duration_seconds' => 300,
            'sort_order' => $section->lessons()->count() + 1,
        ], $attributes));

        if ($videoStatus) {
            $lesson->video()->create([
                'source_path' => "lessons/source/{$lesson->id}.mp4",
                'playlist_path' => $videoStatus === VideoStatus::READY
                    ? "lessons/{$lesson->id}/hls/master.m3u8"
                    : null,
                'duration_seconds' => $attributes['duration_seconds'] ?? 300,
                'status' => $videoStatus,
            ]);
        }

        return $lesson;
    }

    protected function enroll(User $student, Course $course): void
    {
        $student->enrollments()->create([
            'course_id' => $course->id,
            'enrolled_at' => now(),
        ]);
    }

    protected function tokenFor(User $user): string
    {
        return $user->createToken('auth_token')->plainTextToken;
    }
}
