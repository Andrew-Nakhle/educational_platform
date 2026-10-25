<?php

namespace Tests\Feature;

use App\Enums\Course\CourseStatus;
use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * Course update: draft or rejected. Course delete: draft only.
 * Sections and lessons (create/update/delete/reorder): parent course
 * draft or rejected.
 */
class CourseStatusRulesTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    private const CONTENT_LOCKED = 'Sections and lessons can only be changed while the course is draft or rejected.';

    private const LOCKED_STATUSES = [
        CourseStatus::PENDING_PUBLISH,
        CourseStatus::PUBLISHED,
        CourseStatus::ARCHIVED,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTestData();
        Queue::fake();
    }

    public function test_course_can_be_updated_only_when_draft_or_rejected(): void
    {
        $instructor = $this->makeInstructor();
        $this->actingAs($instructor, 'sanctum');

        foreach ([CourseStatus::DRAFT, CourseStatus::REJECTED] as $status) {
            $course = $this->makeCourse($instructor, ['status' => $status]);

            $this->putJson("/api/instructor/courses/{$course->id}", ['title' => 'New title'])
                ->assertOk()
                ->assertJsonPath('course.title', 'New title');
        }

        foreach (self::LOCKED_STATUSES as $status) {
            $course = $this->makeCourse($instructor, ['status' => $status]);

            $this->putJson("/api/instructor/courses/{$course->id}", ['title' => 'New title'])
                ->assertStatus(422)
                ->assertExactJson(['message' => 'Only draft or rejected courses can be updated.']);

            // The multipart alias (POST) follows the same rule.
            $this->postJson("/api/instructor/courses/{$course->id}", ['title' => 'New title'])
                ->assertStatus(422);

            $this->assertSame('Laravel Basics', $course->fresh()->title);
        }
    }

    public function test_course_update_checks_ownership_before_status(): void
    {
        $course = $this->makeCourse(attributes: ['status' => CourseStatus::PUBLISHED]);

        $this->actingAs($this->makeInstructor(), 'sanctum')
            ->putJson("/api/instructor/courses/{$course->id}", ['title' => 'New title'])
            ->assertForbidden();
    }

    public function test_course_can_be_deleted_only_when_draft(): void
    {
        $instructor = $this->makeInstructor();
        $this->actingAs($instructor, 'sanctum');

        foreach ([CourseStatus::REJECTED, ...self::LOCKED_STATUSES] as $status) {
            $course = $this->makeCourse($instructor, ['status' => $status]);

            $this->deleteJson("/api/instructor/courses/{$course->id}")
                ->assertStatus(422)
                ->assertExactJson(['message' => 'Only draft courses can be deleted.']);

            $this->assertNotNull($course->fresh());
        }

        $draft = $this->makeCourse($instructor, ['status' => CourseStatus::DRAFT]);

        $this->deleteJson("/api/instructor/courses/{$draft->id}")
            ->assertOk()
            ->assertExactJson(['message' => 'Course deleted successfully.']);

        $this->assertNull(Course::find($draft->id));
    }

    public function test_sections_and_lessons_are_locked_unless_course_is_draft_or_rejected(): void
    {
        $instructor = $this->makeInstructor();
        $this->actingAs($instructor, 'sanctum');

        foreach (self::LOCKED_STATUSES as $status) {
            $course = $this->makeCourse($instructor, ['status' => $status]);
            $section = $this->makeSection($course);
            $lesson = $this->makeLesson($section);

            $requests = [
                ['postJson', "/api/instructor/courses/{$course->id}/sections", ['title' => 'New section']],
                ['putJson', "/api/instructor/sections/{$section->id}", ['title' => 'Renamed']],
                ['patchJson', "/api/instructor/courses/{$course->id}/sections/reorder", ['ids' => [$section->id]]],
                ['postJson', "/api/instructor/sections/{$section->id}/lessons", [
                    'title' => 'New lesson',
                    'video' => UploadedFile::fake()->create('lesson.mp4', 100, 'video/mp4'),
                ]],
                ['putJson', "/api/instructor/lessons/{$lesson->id}", ['title' => 'Renamed']],
                ['patchJson', "/api/instructor/sections/{$section->id}/lessons/reorder", ['ids' => [$lesson->id]]],
                ['deleteJson', "/api/instructor/lessons/{$lesson->id}", []],
                ['deleteJson', "/api/instructor/sections/{$section->id}", []],
            ];

            foreach ($requests as [$method, $uri, $data]) {
                $this->{$method}($uri, $data)
                    ->assertStatus(422)
                    ->assertExactJson(['message' => self::CONTENT_LOCKED]);
            }

            $this->assertSame(1, $course->sections()->count());
            $this->assertSame(1, $section->lessons()->count());
            $this->assertSame('Getting started', $section->fresh()->title);
            $this->assertSame($lesson->title, $lesson->fresh()->title);
        }

        Queue::assertNothingPushed();
    }

    public function test_sections_and_lessons_can_change_when_course_is_rejected(): void
    {
        $instructor = $this->makeInstructor();
        $course = $this->makeCourse($instructor, ['status' => CourseStatus::REJECTED]);
        $section = $this->makeSection($course);
        $lesson = $this->makeLesson($section);

        $this->actingAs($instructor, 'sanctum');

        $this->postJson("/api/instructor/courses/{$course->id}/sections", ['title' => 'New section'])->assertCreated();
        $this->putJson("/api/instructor/sections/{$section->id}", ['title' => 'Renamed'])->assertOk();
        $this->putJson("/api/instructor/lessons/{$lesson->id}", ['title' => 'Renamed'])->assertOk();
        $this->patchJson("/api/instructor/sections/{$section->id}/lessons/reorder", ['ids' => [$lesson->id]])->assertOk();
        $this->post("/api/instructor/sections/{$section->id}/lessons", [
            'title' => 'New lesson',
            'video' => UploadedFile::fake()->create('lesson.mp4', 100, 'video/mp4'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->deleteJson("/api/instructor/lessons/{$lesson->id}")->assertOk();
        $this->deleteJson("/api/instructor/sections/{$section->id}")->assertOk();
    }

    public function test_reads_are_not_locked(): void
    {
        $instructor = $this->makeInstructor();
        $course = $this->makeCourse($instructor, ['status' => CourseStatus::PUBLISHED]);
        $section = $this->makeSection($course);
        $lesson = $this->makeLesson($section);

        $this->actingAs($instructor, 'sanctum');

        $this->getJson("/api/instructor/courses/{$course->id}")->assertOk();
        $this->getJson("/api/instructor/courses/{$course->id}/sections")->assertOk();
        $this->getJson("/api/instructor/sections/{$section->id}")->assertOk();
        $this->getJson("/api/instructor/sections/{$section->id}/lessons")->assertOk();
        $this->getJson("/api/instructor/lessons/{$lesson->id}")->assertOk();
    }
}
