<?php

namespace Tests\Feature;

use App\Enums\Course\CourseStatus;
use App\Enums\Course\VideoStatus;
use App\Jobs\ProcessLessonVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class ReplaceLessonVideoTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTestData();
        Queue::fake();
    }

    private function upload(int $lessonId, ?UploadedFile $video = null)
    {
        return $this->post(
            "/api/instructor/lessons/{$lessonId}/video",
            ['video' => $video ?? UploadedFile::fake()->create('new.mp4', 200, 'video/mp4')],
            ['Accept' => 'application/json']
        );
    }

    public function test_owner_replaces_the_video_of_a_lesson(): void
    {
        $disk = Storage::disk('local_uploads');
        $instructor = $this->makeInstructor();
        $section = $this->makeSection($this->makeCourse($instructor, ['status' => CourseStatus::DRAFT]));
        $lesson = $this->makeLesson($section, ['duration_seconds' => 300]);
        $video = $lesson->video;

        $disk->put($video->source_path, 'old source');
        $disk->put($video->playlist_path, 'old playlist');
        $disk->put(dirname($video->playlist_path).'/720p/segment0.ts', 'old segment');

        $this->actingAs($instructor, 'sanctum')
            ->upload($lesson->id)
            ->assertOk()
            ->assertJsonPath('message', 'Lesson video replaced successfully. Video processing started.')
            ->assertJsonPath('lesson.id', $lesson->id)
            ->assertJsonPath('lesson.duration_seconds', 0)
            ->assertJsonPath('lesson.video_status', 'processing')
            ->assertJsonPath('lesson.video.id', $video->id)
            ->assertJsonPath('lesson.video.status', 'processing')
            ->assertJsonPath('lesson.video.playlist_path', null);

        $video->refresh();
        $this->assertSame(VideoStatus::PROCESSING, $video->status);
        $this->assertNull($video->playlist_path);
        $this->assertSame(0, $video->duration_seconds);
        $this->assertStringStartsWith('lessons/source/', $video->source_path);
        $this->assertNotSame("lessons/source/{$lesson->id}.mp4", $video->source_path);

        // New source stored; old source and the old HLS directory removed.
        $disk->assertExists($video->source_path);
        $disk->assertMissing("lessons/source/{$lesson->id}.mp4");
        $disk->assertMissing("lessons/{$lesson->id}/hls/master.m3u8");
        $disk->assertMissing("lessons/{$lesson->id}/hls/720p/segment0.ts");

        Queue::assertPushed(ProcessLessonVideo::class, fn ($job) => $job->lessonVideoId === $video->id);
    }

    public function test_rejected_course_lessons_can_get_a_new_video_and_missing_video_rows_are_created(): void
    {
        $instructor = $this->makeInstructor();
        $section = $this->makeSection($this->makeCourse($instructor, ['status' => CourseStatus::REJECTED]));
        $lesson = $this->makeLesson($section, videoStatus: null);

        $this->actingAs($instructor, 'sanctum')
            ->upload($lesson->id)
            ->assertOk()
            ->assertJsonPath('lesson.video_status', 'processing');

        $this->assertNotNull($lesson->video()->first());
        Queue::assertPushed(ProcessLessonVideo::class, 1);
    }

    public function test_failed_video_can_be_replaced(): void
    {
        $instructor = $this->makeInstructor();
        $section = $this->makeSection($this->makeCourse($instructor, ['status' => CourseStatus::DRAFT]));
        $lesson = $this->makeLesson($section, videoStatus: VideoStatus::FAILED);

        $this->actingAs($instructor, 'sanctum')
            ->upload($lesson->id)
            ->assertOk();

        $this->assertSame(VideoStatus::PROCESSING, $lesson->video->fresh()->status);
    }

    public function test_replace_is_locked_unless_course_is_draft_or_rejected(): void
    {
        $instructor = $this->makeInstructor();
        $this->actingAs($instructor, 'sanctum');

        foreach ([CourseStatus::PENDING_PUBLISH, CourseStatus::PUBLISHED, CourseStatus::ARCHIVED] as $status) {
            $lesson = $this->makeLesson($this->makeSection($this->makeCourse($instructor, ['status' => $status])));

            $this->upload($lesson->id)
                ->assertStatus(422)
                ->assertExactJson(['message' => 'Sections and lessons can only be changed while the course is draft or rejected.']);

            $this->assertSame(VideoStatus::READY, $lesson->video->fresh()->status);
        }

        Queue::assertNothingPushed();
    }

    public function test_replace_checks_auth_owner_lesson_and_file(): void
    {
        $lesson = $this->makeLesson($this->makeSection($this->makeCourse(attributes: ['status' => CourseStatus::DRAFT])));

        $this->upload($lesson->id)->assertUnauthorized();

        $this->actingAs($this->makeStudent(), 'sanctum')
            ->upload($lesson->id)
            ->assertForbidden();

        $this->actingAs($this->makeInstructor(), 'sanctum')
            ->upload($lesson->id)
            ->assertForbidden()
            ->assertExactJson(['message' => 'You are not authorized to update this lesson.']);

        $this->upload(999)
            ->assertNotFound()
            ->assertExactJson(['message' => 'Lesson not found.']);

        $owner = $lesson->section->course->instructorProfile->user;
        $this->actingAs($owner, 'sanctum');

        $this->postJson("/api/instructor/lessons/{$lesson->id}/video", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['video']);

        $this->upload($lesson->id, UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['video']);

        config(['uploads.video_max_kb' => 100]);

        $this->upload($lesson->id, UploadedFile::fake()->create('big.mp4', 101, 'video/mp4'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['video']);

        $this->assertSame(VideoStatus::READY, $lesson->video->fresh()->status);
        Queue::assertNothingPushed();
    }
}
