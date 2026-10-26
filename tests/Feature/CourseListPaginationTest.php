<?php

namespace Tests\Feature;

use App\Enums\Course\CourseStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * ?page= adds { message, data, meta }; without it the lists keep their
 * old shape. Also students_count and the public instructor fields.
 */
class CourseListPaginationTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTestData();
    }

    public function test_student_catalog_without_page_keeps_its_shape(): void
    {
        $instructor = $this->makeInstructor();

        for ($i = 1; $i <= 13; $i++) {
            $this->makeCourse($instructor, ['title' => "Course {$i}"]);
        }

        $response = $this->getJson('/api/student/courses')->assertOk();

        $this->assertSame(['message', 'data'], array_keys($response->json()));
        $this->assertCount(12, $response->json('data'));

        // per_page alone does not switch to the paginated shape.
        $response = $this->getJson('/api/student/courses?per_page=5')->assertOk();
        $this->assertSame(['message', 'data'], array_keys($response->json()));
        $this->assertCount(12, $response->json('data'));
    }

    public function test_student_catalog_with_page_returns_meta(): void
    {
        $instructor = $this->makeInstructor();

        for ($i = 1; $i <= 13; $i++) {
            $this->makeCourse($instructor, ['title' => "Course {$i}"]);
        }

        $this->makeCourse($instructor, ['title' => 'Draft', 'status' => CourseStatus::DRAFT]);

        $response = $this->getJson('/api/student/courses?page=2&per_page=5')
            ->assertOk()
            ->assertJsonPath('message', 'Courses retrieved successfully.')
            ->assertJsonPath('meta', [
                'current_page' => 2,
                'last_page' => 3,
                'total' => 13,
                'per_page' => 5,
            ]);

        $this->assertSame(['message', 'data', 'meta'], array_keys($response->json()));
        $this->assertSame(
            ['Course 8', 'Course 7', 'Course 6', 'Course 5', 'Course 4'],
            array_column($response->json('data'), 'title')
        );

        $this->getJson('/api/student/courses?page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 12)
            ->assertJsonCount(12, 'data');
    }

    public function test_pagination_params_are_validated_on_every_list(): void
    {
        $this->getJson('/api/student/courses?page=1&per_page=51')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);

        $this->actingAs($this->makeInstructor(), 'sanctum')
            ->getJson('/api/instructor/courses?page=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['page']);

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->getJson('/api/admin/courses?page=1&per_page=abc')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_instructor_courses_keep_the_courses_key_without_page(): void
    {
        $instructor = $this->makeInstructor();
        $this->makeCourse($instructor, ['title' => 'Mine A']);
        $this->makeCourse($instructor, ['title' => 'Mine B', 'status' => CourseStatus::DRAFT]);
        $this->makeCourse(attributes: ['title' => 'Someone else']);

        $this->actingAs($instructor, 'sanctum');

        $response = $this->getJson('/api/instructor/courses')->assertOk();

        $this->assertSame(['courses'], array_keys($response->json()));
        $this->assertEqualsCanonicalizing(['Mine A', 'Mine B'], array_column($response->json('courses'), 'title'));

        $this->getJson('/api/instructor/courses?status=draft')
            ->assertOk()
            ->assertJsonCount(1, 'courses')
            ->assertJsonPath('courses.0.title', 'Mine B');
    }

    public function test_instructor_courses_with_page_return_meta(): void
    {
        $instructor = $this->makeInstructor();

        for ($i = 1; $i <= 3; $i++) {
            $this->makeCourse($instructor, ['title' => "Mine {$i}"]);
        }

        $this->makeCourse($instructor, ['title' => 'Draft', 'status' => CourseStatus::DRAFT]);
        $this->makeCourse(attributes: ['title' => 'Someone else']);

        $this->actingAs($instructor, 'sanctum');

        $response = $this->getJson('/api/instructor/courses?page=1&per_page=2')
            ->assertOk()
            ->assertJsonPath('message', 'Courses retrieved successfully.')
            ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 2, 'total' => 4, 'per_page' => 2])
            ->assertJsonPath('data.0.title', 'Draft')
            ->assertJsonPath('data.1.title', 'Mine 3');

        $this->assertSame(['message', 'data', 'meta'], array_keys($response->json()));

        $this->getJson('/api/instructor/courses?page=1&status=published')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 15);
    }

    public function test_admin_courses_keep_the_courses_key_without_page(): void
    {
        $this->makeCourse();
        $this->makeCourse(attributes: ['status' => CourseStatus::PENDING_PUBLISH]);

        $this->actingAs($this->makeAdmin(), 'sanctum');

        $response = $this->getJson('/api/admin/courses')->assertOk();
        $this->assertSame(['courses'], array_keys($response->json()));
        $this->assertCount(2, $response->json('courses'));

        $response = $this->getJson('/api/admin/courses?status=pending_publish&page=1&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 1, 'total' => 1, 'per_page' => 1])
            ->assertJsonPath('data.0.status', 'pending_publish');

        $this->assertSame(['message', 'data', 'meta'], array_keys($response->json()));
    }

    public function test_instructor_and_admin_course_resources_have_students_count(): void
    {
        $instructor = $this->makeInstructor();
        $course = $this->makeCourse($instructor);
        $this->enroll($this->makeStudent(), $course);
        $this->enroll($this->makeStudent(), $course);

        $this->actingAs($instructor, 'sanctum');

        $this->getJson('/api/instructor/courses')->assertJsonPath('courses.0.students_count', 2);
        $this->getJson('/api/instructor/courses?page=1')->assertJsonPath('data.0.students_count', 2);
        $this->getJson("/api/instructor/courses/{$course->id}")->assertJsonPath('course.students_count', 2);

        $this->actingAs($this->makeAdmin(), 'sanctum');

        $this->getJson('/api/admin/courses')->assertJsonPath('courses.0.students_count', 2);
        $this->getJson('/api/admin/courses?page=1')->assertJsonPath('data.0.students_count', 2);
        $this->getJson("/api/admin/courses/{$course->id}")->assertJsonPath('course.students_count', 2);

        // The public catalog has it too (B6), with the same count.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/student/courses')->assertJsonPath('data.0.students_count', 2);
        $this->getJson("/api/student/courses/{$course->id}")->assertJsonPath('data.students_count', 2);
    }

    public function test_course_instructor_object_has_public_profile_fields_only(): void
    {
        $instructor = $this->makeInstructor(profile: [
            'teaching_major' => 'software_engineering',
            'bio' => 'Ten years of PHP.',
        ]);
        $course = $this->makeCourse($instructor);

        $responses = [
            $this->getJson('/api/student/courses')->json('data.0.instructor'),
            $this->getJson("/api/student/courses/{$course->id}")->json('data.instructor'),
            $this->actingAs($this->makeAdmin(), 'sanctum')->getJson('/api/admin/courses')->json('courses.0.instructor'),
        ];

        foreach ($responses as $object) {
            $this->assertSame(
                ['id', 'first_name', 'father_name', 'last_name', 'avatar', 'teaching_major', 'bio'],
                array_keys($object)
            );
            $this->assertStringContainsString('instructors/avatars/example.png', $object['avatar']);
            $this->assertSame('software_engineering', $object['teaching_major']);
            $this->assertSame('Ten years of PHP.', $object['bio']);
        }
    }
}
