<?php

namespace Tests\Feature;

use App\Enums\Course\CourseStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * Documents the current course status transitions:
 * draft -> pending_publish (submit) -> published (approve) | rejected (reject);
 * any non-archived status -> archived (archive); archived -> draft (restore).
 */
class CourseStatusFlowTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTestData();
    }

    public function test_submit_then_approve_publishes_the_course(): void
    {
        $instructor = $this->makeInstructor();
        $course = $this->makeCourse($instructor, ['status' => CourseStatus::DRAFT]);

        $this->actingAs($instructor, 'sanctum')
            ->postJson("/api/instructor/courses/{$course->id}/submit")
            ->assertOk()
            ->assertJsonPath('status', 'pending_publish');

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->patchJson("/api/admin/courses/{$course->id}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'published');
    }

    public function test_reject_sets_rejected(): void
    {
        $course = $this->makeCourse(attributes: ['status' => CourseStatus::PENDING_PUBLISH]);

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->patchJson("/api/admin/courses/{$course->id}/reject")
            ->assertOk()
            ->assertJsonPath('status', 'rejected');
    }

    public function test_only_pending_courses_can_be_approved_or_rejected(): void
    {
        $course = $this->makeCourse(attributes: ['status' => CourseStatus::DRAFT]);

        $this->actingAs($this->makeAdmin(), 'sanctum');

        $this->patchJson("/api/admin/courses/{$course->id}/approve")->assertStatus(422);
        $this->patchJson("/api/admin/courses/{$course->id}/reject")->assertStatus(422);
    }

    public function test_only_drafts_can_be_submitted(): void
    {
        $instructor = $this->makeInstructor();
        $course = $this->makeCourse($instructor, ['status' => CourseStatus::REJECTED]);

        $this->actingAs($instructor, 'sanctum')
            ->postJson("/api/instructor/courses/{$course->id}/submit")
            ->assertStatus(422);
    }

    public function test_archive_then_restore_returns_to_draft(): void
    {
        $instructor = $this->makeInstructor();
        $course = $this->makeCourse($instructor, ['status' => CourseStatus::PUBLISHED]);

        $this->actingAs($instructor, 'sanctum');

        $this->patchJson("/api/instructor/courses/{$course->id}/archive")
            ->assertOk()
            ->assertJsonPath('status', 'archived');

        // Restore always goes back to draft, not to the status before archiving.
        $this->patchJson("/api/instructor/courses/{$course->id}/restore")
            ->assertOk()
            ->assertJsonPath('status', 'draft');
    }

    public function test_other_instructor_cannot_submit(): void
    {
        $course = $this->makeCourse(attributes: ['status' => CourseStatus::DRAFT]);

        $this->actingAs($this->makeInstructor(), 'sanctum')
            ->postJson("/api/instructor/courses/{$course->id}/submit")
            ->assertForbidden();
    }
}
