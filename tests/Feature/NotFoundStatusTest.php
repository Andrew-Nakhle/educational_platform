<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * Missing resources must return 404 (previously some returned 200 with
 * a "not found" message). Response keys are unchanged.
 */
class NotFoundStatusTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTestData();
    }

    public function test_admin_course_actions_return_404_for_missing_course(): void
    {
        $this->actingAs($this->makeAdmin(), 'sanctum');

        $this->patchJson('/api/admin/courses/999/approve')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Course not found']);

        $this->patchJson('/api/admin/courses/999/reject')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Course not found']);
    }

    public function test_instructor_endpoints_return_404_for_missing_resources(): void
    {
        $this->actingAs($this->makeInstructor(), 'sanctum');

        $this->getJson('/api/instructor/courses/999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Course not found.']);

        $this->postJson('/api/instructor/courses/999/submit')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Course not found.']);

        $this->postJson('/api/instructor/lessons/999/video/retry')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Lesson video not found.']);

        $this->getJson('/api/instructor/sections/999/lessons')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Section not found.']);

        $this->getJson('/api/instructor/lessons/999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Lesson not found.']);

        $this->putJson('/api/instructor/lessons/999', ['title' => 'New title'])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Lesson not found.']);

        $this->putJson('/api/instructor/sections/999', ['title' => 'New title'])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Section not found.']);
    }

    public function test_find_or_fail_lookups_return_the_same_404_json(): void
    {
        $this->actingAs($this->makeAdmin(), 'sanctum');

        foreach ([
            ['get', '/api/admin/users/999', 'User not found.'],
            ['get', '/api/admin/courses/999', 'Course not found.'],
            ['get', '/api/admin/instructor-requests/999', 'User not found.'],
            ['patch', '/api/admin/instructor-requests/999/approve', 'User not found.'],
            ['patch', '/api/admin/instructor-requests/999/reject', 'User not found.'],
        ] as [$method, $uri, $message]) {
            $this->json($method, $uri)
                ->assertNotFound()
                ->assertExactJson(['message' => $message]);
        }

        $this->actingAs($this->makeInstructor(), 'sanctum');

        foreach ([
            ['delete', '/api/instructor/courses/999', 'Course not found.'],
            ['delete', '/api/instructor/sections/999', 'Section not found.'],
            ['delete', '/api/instructor/lessons/999', 'Lesson not found.'],
        ] as [$method, $uri, $message]) {
            $this->json($method, $uri)
                ->assertNotFound()
                ->assertExactJson(['message' => $message]);
        }

        $this->getJson('/api/student/courses/999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Course not found.']);
    }
}
