<?php

namespace Tests\Feature;

use App\Enums\Course\CourseStatus;
use App\Enums\Course\VideoStatus;
use App\Models\CourseReview;
use App\Models\LessonProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class CourseCatalogDetailsTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTestData();
    }

    public function test_guest_sees_course_details_with_is_enrolled_false_and_no_completed_flags(): void
    {
        $course = $this->makeCourse(attributes: ['price' => '19.5']);
        $this->makeLesson($this->makeSection($course), ['duration_seconds' => 125]);

        $response = $this->getJson("/api/student/courses/{$course->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Course details retrieved successfully.')
            ->assertJsonPath('data.is_enrolled', false)
            ->assertJsonPath('data.price', '19.50')
            ->assertJsonPath('data.rating_average', null)
            ->assertJsonPath('data.ratings_count', 0)
            ->assertJsonPath('data.sections.0.lessons.0.duration_seconds', 125);

        // Upstream always sends `completed` (false unless enrolled).
        $lesson = $response->json('data.sections.0.lessons.0');
        $this->assertFalse($lesson['completed']);
        $this->assertArrayNotHasKey('video_status', $lesson);
    }

    public function test_enrolled_student_sees_is_enrolled_and_completed_flags(): void
    {
        $student = $this->makeStudent();
        $course = $this->makeCourse();
        $section = $this->makeSection($course);
        $done = $this->makeLesson($section);
        $this->makeLesson($section);
        $this->enroll($student, $course);

        LessonProgress::create([
            'user_id' => $student->id,
            'lesson_id' => $done->id,
            'completed_at' => now(),
        ]);

        $this->withToken($this->tokenFor($student))
            ->getJson("/api/student/courses/{$course->id}")
            ->assertOk()
            ->assertJsonPath('data.is_enrolled', true)
            ->assertJsonPath('data.sections.0.lessons.0.completed', true)
            ->assertJsonPath('data.sections.0.lessons.1.completed', false);
    }

    public function test_not_enrolled_student_and_other_roles_get_is_enrolled_false(): void
    {
        $course = $this->makeCourse();
        $this->makeLesson($this->makeSection($course));

        foreach ([$this->makeStudent(), $this->makeInstructor(), $this->makeAdmin()] as $user) {
            $response = $this->withToken($this->tokenFor($user))
                ->getJson("/api/student/courses/{$course->id}")
                ->assertOk()
                ->assertJsonPath('data.is_enrolled', false);

            $this->assertFalse($response->json('data.sections.0.lessons.0.completed'));
        }
    }

    public function test_invalid_token_is_treated_as_guest(): void
    {
        $course = $this->makeCourse();

        $this->withToken('1|not-a-real-token')
            ->getJson("/api/student/courses/{$course->id}")
            ->assertOk()
            ->assertJsonPath('data.is_enrolled', false);
    }

    public function test_course_list_has_is_enrolled_and_ratings(): void
    {
        $student = $this->makeStudent();
        $enrolled = $this->makeCourse(attributes: ['title' => 'Enrolled']);
        $other = $this->makeCourse(attributes: ['title' => 'Other']);
        $this->enroll($student, $enrolled);

        foreach ([5, 4] as $i => $rating) {
            CourseReview::create([
                'user_id' => $this->makeStudent()->id,
                'course_id' => $enrolled->id,
                'rating' => $rating,
            ]);
        }

        $courses = collect(
            $this->withToken($this->tokenFor($student))
                ->getJson('/api/student/courses')
                ->assertOk()
                ->json('data')
        )->keyBy('title');

        $this->assertTrue($courses['Enrolled']['is_enrolled']);
        $this->assertSame(4.5, $courses['Enrolled']['rating_average']);
        $this->assertSame(2, $courses['Enrolled']['ratings_count']);
        $this->assertFalse($courses['Other']['is_enrolled']);
        $this->assertNull($courses['Other']['rating_average']);
    }

    public function test_guest_course_list_has_is_enrolled_false(): void
    {
        $this->enroll($this->makeStudent(), $this->makeCourse());

        $this->getJson('/api/student/courses')
            ->assertOk()
            ->assertJsonPath('data.0.is_enrolled', false)
            ->assertJsonPath('data.0.ratings_count', 0)
            ->assertJsonPath('data.0.price', '0.00');
    }

    public function test_unpublished_course_details_return_404(): void
    {
        $course = $this->makeCourse(attributes: ['status' => CourseStatus::DRAFT]);

        $this->getJson("/api/student/courses/{$course->id}")->assertNotFound();
        $this->getJson('/api/student/courses/999')->assertNotFound();
    }

    public function test_admin_course_details_expose_video_status_and_ratings(): void
    {
        $course = $this->makeCourse();
        $section = $this->makeSection($course);
        $this->makeLesson($section, videoStatus: VideoStatus::PROCESSING);
        $this->makeLesson($section, videoStatus: VideoStatus::FAILED);

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->getJson("/api/admin/courses/{$course->id}")
            ->assertOk()
            ->assertJsonPath('course.sections.0.lessons.0.video_status', 'processing')
            ->assertJsonPath('course.sections.0.lessons.1.video_status', 'failed')
            ->assertJsonPath('course.ratings_count', 0)
            ->assertJsonPath('course.is_enrolled', false);
    }

    public function test_instructor_lesson_responses_expose_video_status(): void
    {
        $instructor = $this->makeInstructor();
        $course = $this->makeCourse($instructor);
        $section = $this->makeSection($course);
        $lesson = $this->makeLesson($section, videoStatus: VideoStatus::READY);

        $this->actingAs($instructor, 'sanctum');

        $this->getJson("/api/instructor/sections/{$section->id}/lessons")
            ->assertOk()
            ->assertJsonPath('0.video_status', 'ready');

        $this->getJson("/api/instructor/lessons/{$lesson->id}")
            ->assertOk()
            ->assertJsonPath('lesson.video_status', 'ready')
            ->assertJsonPath('lesson.video.status', 'ready');
    }
}
