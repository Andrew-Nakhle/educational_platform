<?php

namespace Tests\Feature;

use App\Enums\Course\CourseStatus;
use App\Enums\User\UserStatus;
use App\Models\Category;
use App\Models\Course;
use App\Models\InstructorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.upload_disk' => 'local_uploads']);
        Storage::fake('local_uploads');
    }

    private function createInstructorProfile(): InstructorProfile
    {
        $user = User::create([
            'email' => 'instructor@example.com',
            'phone' => '0999000333',
            'gender' => 'male',
            'birthday' => '1985-03-10',
            'password' => Hash::make('secret-password'),
            'status' => UserStatus::ACTIVE,
        ]);

        return $user->instructorProfile()->create([
            'first_name' => 'Khaled',
            'father_name' => 'Samir',
            'last_name' => 'Nasser',
            'avatar' => 'instructors/avatars/avatar.png',
            'teaching_major' => 'computer_science',
        ]);
    }

    private function createCourse(
        InstructorProfile $instructor,
        Category $category,
        array $attributes = []
    ): Course {
        return Course::create(array_merge([
            'instructor_profile_id' => $instructor->id,
            'category_id' => $category->id,
            'title' => 'Laravel Basics',
            'description' => 'Build APIs with Laravel.',
            'price' => 0,
            'thumbnail' => 'courses/thumbnails/laravel.png',
            'language' => 'English',
            'difficulty_level' => 'easy',
            'what_you_will_learn' => 'Routing, Eloquent, testing.',
            'status' => CourseStatus::PUBLISHED,
        ], $attributes));
    }

    public function test_categories_are_public(): void
    {
        Category::create(['name' => 'Programming']);
        Category::create(['name' => 'Design']);

        $this->get('/api/categories')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Programming')
            ->assertJsonStructure(['data' => [['id', 'name']]]);
    }

    public function test_courses_are_public_and_only_published_are_listed(): void
    {
        $instructor = $this->createInstructorProfile();
        $category = Category::create(['name' => 'Programming']);

        $this->createCourse($instructor, $category);
        $this->createCourse($instructor, $category, [
            'title' => 'Draft Course',
            'status' => CourseStatus::DRAFT,
        ]);
        $this->createCourse($instructor, $category, [
            'title' => 'Pending Course',
            'status' => CourseStatus::PENDING_PUBLISH,
        ]);

        $this->get('/api/student/courses')
            ->assertOk()
            ->assertJsonPath('message', 'Courses retrieved successfully.')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Laravel Basics')
            ->assertJsonPath('data.0.status', 'published')
            ->assertJsonPath('data.0.category.name', 'Programming')
            ->assertJsonPath('data.0.instructor.first_name', 'Khaled');
    }

    public function test_courses_can_be_filtered_by_category_and_search(): void
    {
        $instructor = $this->createInstructorProfile();
        $programming = Category::create(['name' => 'Programming']);
        $design = Category::create(['name' => 'Design']);

        $this->createCourse($instructor, $programming);
        $this->createCourse($instructor, $design, ['title' => 'Figma Basics']);

        $this->getJson('/api/student/courses?category_id='.$design->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Figma Basics');

        $this->getJson('/api/student/courses?search=Laravel')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Laravel Basics');
    }

    public function test_courses_reject_unknown_category(): void
    {
        $this->get('/api/student/courses?category_id=999')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);
    }
}
