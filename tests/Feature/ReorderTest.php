<?php

namespace Tests\Feature;

use App\Enums\Course\CourseStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class ReorderTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    // Sections and lessons can only be reordered while the course is draft or rejected.
    private const DRAFT = ['status' => CourseStatus::DRAFT];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTestData();
    }

    public function test_instructor_reorders_the_sections_of_their_course(): void
    {
        $instructor = $this->makeInstructor();
        $course = $this->makeCourse($instructor, self::DRAFT);
        [$a, $b, $c] = [$this->makeSection($course), $this->makeSection($course), $this->makeSection($course)];

        $this->actingAs($instructor, 'sanctum')
            ->patchJson("/api/instructor/courses/{$course->id}/sections/reorder", ['ids' => [$c->id, $a->id, $b->id]])
            ->assertOk()
            ->assertExactJson(['message' => 'Sections reordered successfully.']);

        $this->assertSame([1, 2, 3], [$c->fresh()->sort_order, $a->fresh()->sort_order, $b->fresh()->sort_order]);

        $this->getJson("/api/instructor/courses/{$course->id}/sections")
            ->assertOk()
            ->assertJsonPath('sections.0.id', $c->id)
            ->assertJsonPath('sections.2.id', $b->id);
    }

    public function test_section_ids_must_be_exactly_the_course_sections(): void
    {
        $instructor = $this->makeInstructor();
        $course = $this->makeCourse($instructor, self::DRAFT);
        $a = $this->makeSection($course);
        $b = $this->makeSection($course);
        $foreign = $this->makeSection($this->makeCourse($instructor, self::DRAFT));

        $this->actingAs($instructor, 'sanctum');

        foreach ([
            [$a->id],                         // missing one
            [$a->id, $b->id, $foreign->id],   // section of another course
            [$a->id, $foreign->id],           // swapped for a foreign one
            [$a->id, 999],                    // unknown id
        ] as $ids) {
            $this->patchJson("/api/instructor/courses/{$course->id}/sections/reorder", ['ids' => $ids])
                ->assertStatus(422)
                ->assertJsonPath('errors.ids.0', 'The ids must list every section of this course exactly once.');
        }

        foreach ([[], 'nope', [$a->id, $a->id], [$a->id, 'x']] as $ids) {
            $this->patchJson("/api/instructor/courses/{$course->id}/sections/reorder", ['ids' => $ids])
                ->assertStatus(422);
        }

        $this->assertSame([1, 2], [$a->fresh()->sort_order, $b->fresh()->sort_order]);
        $this->assertSame(1, $foreign->fresh()->sort_order);
    }

    public function test_section_reorder_checks_owner_role_and_course(): void
    {
        $course = $this->makeCourse(attributes: self::DRAFT);
        $section = $this->makeSection($course);
        $uri = "/api/instructor/courses/{$course->id}/sections/reorder";

        $this->patchJson($uri, ['ids' => [$section->id]])->assertUnauthorized();

        $this->actingAs($this->makeStudent(), 'sanctum')
            ->patchJson($uri, ['ids' => [$section->id]])
            ->assertForbidden();

        $this->actingAs($this->makeInstructor(), 'sanctum')
            ->patchJson($uri, ['ids' => [$section->id]])
            ->assertForbidden()
            ->assertExactJson(['message' => 'You are not authorized to reorder these sections.']);

        $this->patchJson('/api/instructor/courses/999/sections/reorder', ['ids' => [1]])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Course not found.']);
    }

    public function test_instructor_reorders_the_lessons_of_their_section(): void
    {
        $instructor = $this->makeInstructor();
        $section = $this->makeSection($this->makeCourse($instructor, self::DRAFT));
        [$a, $b, $c] = [$this->makeLesson($section), $this->makeLesson($section), $this->makeLesson($section)];

        $this->actingAs($instructor, 'sanctum')
            ->patchJson("/api/instructor/sections/{$section->id}/lessons/reorder", ['ids' => [$b->id, $c->id, $a->id]])
            ->assertOk()
            ->assertExactJson(['message' => 'Lessons reordered successfully.']);

        $this->assertSame([1, 2, 3], [$b->fresh()->sort_order, $c->fresh()->sort_order, $a->fresh()->sort_order]);

        $this->getJson("/api/instructor/sections/{$section->id}/lessons")
            ->assertOk()
            ->assertJsonPath('0.id', $b->id)
            ->assertJsonPath('2.id', $a->id);
    }

    public function test_lesson_reorder_validates_ids_owner_and_section(): void
    {
        $instructor = $this->makeInstructor();
        $course = $this->makeCourse($instructor, self::DRAFT);
        $section = $this->makeSection($course);
        $a = $this->makeLesson($section);
        $b = $this->makeLesson($section);
        $foreign = $this->makeLesson($this->makeSection($course));
        $uri = "/api/instructor/sections/{$section->id}/lessons/reorder";

        $this->actingAs($instructor, 'sanctum');

        foreach ([[$b->id], [$a->id, $b->id, $foreign->id], [$a->id, $foreign->id]] as $ids) {
            $this->patchJson($uri, ['ids' => $ids])
                ->assertStatus(422)
                ->assertJsonPath('errors.ids.0', 'The ids must list every lesson of this section exactly once.');
        }

        $this->patchJson($uri, ['ids' => [$a->id, $a->id]])->assertStatus(422);
        $this->assertSame([1, 2], [$a->fresh()->sort_order, $b->fresh()->sort_order]);

        $this->patchJson('/api/instructor/sections/999/lessons/reorder', ['ids' => [1]])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Section not found.']);

        $this->actingAs($this->makeInstructor(), 'sanctum')
            ->patchJson($uri, ['ids' => [$b->id, $a->id]])
            ->assertForbidden()
            ->assertExactJson(['message' => 'You are not authorized to reorder these lessons.']);

        $this->assertSame([1, 2], [$a->fresh()->sort_order, $b->fresh()->sort_order]);
    }
}
