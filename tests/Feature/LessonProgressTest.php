<?php

namespace Tests\Feature;

use App\Models\LessonProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class LessonProgressTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTestData();
    }

    public function test_enrolled_student_can_complete_a_lesson_idempotently(): void
    {
        $student = $this->makeStudent();
        $course = $this->makeCourse();
        $section = $this->makeSection($course);
        $lesson = $this->makeLesson($section);
        $this->makeLesson($section);
        $this->makeLesson($this->makeSection($course));
        $this->enroll($student, $course);

        $this->actingAs($student, 'sanctum');

        foreach ([1, 2] as $attempt) {
            $this->postJson("/api/student/lessons/{$lesson->id}/complete")
                ->assertOk()
                ->assertExactJson([
                    'message' => 'Lesson marked as completed.',
                    'data' => [
                        'lesson_id' => $lesson->id,
                        'completed' => true,
                        'progress' => [
                            'course_id' => $course->id,
                            'completed_lessons' => 1,
                            'total_lessons' => 3,
                            'percent' => 33,
                        ],
                    ],
                ]);
        }

        $this->assertSame(1, LessonProgress::count());
    }

    public function test_student_can_undo_a_completed_lesson(): void
    {
        $student = $this->makeStudent();
        $course = $this->makeCourse();
        $lesson = $this->makeLesson($this->makeSection($course));
        $this->enroll($student, $course);

        $this->actingAs($student, 'sanctum');
        $this->postJson("/api/student/lessons/{$lesson->id}/complete")->assertOk();

        foreach ([1, 2] as $attempt) {
            $this->deleteJson("/api/student/lessons/{$lesson->id}/complete")
                ->assertOk()
                ->assertJsonPath('message', 'Lesson marked as incomplete.')
                ->assertJsonPath('data.lesson_id', $lesson->id)
                ->assertJsonPath('data.completed', false)
                ->assertJsonPath('data.progress.completed_lessons', 0)
                ->assertJsonPath('data.progress.total_lessons', 1)
                ->assertJsonPath('data.progress.percent', 0);
        }

        $this->assertSame(0, LessonProgress::count());
    }

    public function test_not_enrolled_student_gets_403(): void
    {
        $lesson = $this->makeLesson($this->makeSection($this->makeCourse()));

        $this->actingAs($this->makeStudent(), 'sanctum');

        $this->postJson("/api/student/lessons/{$lesson->id}/complete")
            ->assertForbidden()
            ->assertJsonPath('message', 'You are not enrolled in this course.');

        $this->deleteJson("/api/student/lessons/{$lesson->id}/complete")->assertForbidden();
    }

    public function test_missing_lesson_returns_404(): void
    {
        $this->actingAs($this->makeStudent(), 'sanctum')
            ->postJson('/api/student/lessons/999/complete')
            ->assertNotFound();
    }

    public function test_requires_authentication_and_student_role(): void
    {
        $lesson = $this->makeLesson($this->makeSection($this->makeCourse()));

        $this->postJson("/api/student/lessons/{$lesson->id}/complete")->assertUnauthorized();

        $this->actingAs($this->makeInstructor(), 'sanctum')
            ->postJson("/api/student/lessons/{$lesson->id}/complete")
            ->assertForbidden();
    }

    public function test_my_courses_keeps_courses_key_and_adds_progress(): void
    {
        $student = $this->makeStudent();
        $course = $this->makeCourse();
        $section = $this->makeSection($course);
        $first = $this->makeLesson($section);
        $second = $this->makeLesson($section);
        $this->makeLesson($section);
        $untouched = $this->makeCourse(attributes: ['title' => 'Untouched']);
        $this->enroll($student, $course);
        $this->enroll($student, $untouched);

        $this->travelTo(now()->subMinutes(10));
        LessonProgress::create(['user_id' => $student->id, 'lesson_id' => $second->id, 'completed_at' => now()]);
        $this->travelBack();
        LessonProgress::create(['user_id' => $student->id, 'lesson_id' => $first->id, 'completed_at' => now()]);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson('/api/student/my-courses')
            ->assertOk()
            ->assertJsonPath('message', 'Enrolled courses retrieved successfully.')
            ->assertJsonCount(2, 'courses')
            ->assertJsonCount(2, 'data');

        $this->assertSame($response->json('courses'), $response->json('data'));

        $item = collect($response->json('data'))->firstWhere('id', $course->id);
        $this->assertSame('Laravel Basics', $item['title']);
        $this->assertNotNull($item['enrolled_at']);
        $this->assertSame($first->id, $item['last_lesson_id']);
        $this->assertSame(
            ['completed_lessons' => 2, 'total_lessons' => 3, 'percent' => 67],
            $item['progress']
        );

        $empty = collect($response->json('data'))->firstWhere('id', $untouched->id);
        $this->assertNull($empty['last_lesson_id']);
        $this->assertSame(
            ['completed_lessons' => 0, 'total_lessons' => 0, 'percent' => 0],
            $empty['progress']
        );
    }

    public function test_my_courses_requires_student(): void
    {
        $this->getJson('/api/student/my-courses')->assertUnauthorized();

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->getJson('/api/student/my-courses')
            ->assertForbidden();
    }
}
