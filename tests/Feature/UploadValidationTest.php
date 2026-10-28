<?php

namespace Tests\Feature;

use App\Enums\Course\CourseStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class UploadValidationTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTestData();
        Queue::fake();
    }

    private function coursePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Testing Laravel APIs',
            'description' => 'Feature tests for REST APIs.',
            'price' => '29.00',
            'category_id' => $this->makeCategory()->id,
            'language' => 'English',
            'difficulty_level' => 'medium',
            'what_you_will_learn' => 'HTTP tests.',
            'thumbnail' => UploadedFile::fake()->image('thumbnail.png'),
        ], $overrides);
    }

    public function test_upload_limits_have_documented_defaults(): void
    {
        $this->assertSame(5120, config('uploads.thumbnail_max_kb'));
        $this->assertSame(2097152, config('uploads.video_max_kb'));
    }

    public function test_thumbnail_accepts_jpg_jpeg_png_webp_up_to_the_limit(): void
    {
        $this->actingAs($this->makeInstructor(), 'sanctum');

        foreach (['a.jpg', 'b.jpeg', 'c.png', 'd.webp'] as $name) {
            $this->post('/api/instructor/courses', $this->coursePayload([
                'thumbnail' => UploadedFile::fake()->image($name)->size(5120),
            ]), ['Accept' => 'application/json'])->assertCreated();
        }
    }

    public function test_thumbnail_rejects_other_types_and_large_files(): void
    {
        $instructor = $this->makeInstructor();
        $course = $this->makeCourse($instructor, ['status' => CourseStatus::DRAFT]);

        $this->actingAs($instructor, 'sanctum');

        foreach ([
            UploadedFile::fake()->image('big.png')->size(5121),
            UploadedFile::fake()->image('anim.gif'),
            UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ] as $file) {
            $this->post('/api/instructor/courses', $this->coursePayload(['thumbnail' => $file]), ['Accept' => 'application/json'])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['thumbnail']);

            $this->post("/api/instructor/courses/{$course->id}", ['thumbnail' => $file], ['Accept' => 'application/json'])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['thumbnail']);
        }
    }

    public function test_thumbnail_limit_comes_from_config(): void
    {
        config(['uploads.thumbnail_max_kb' => 100]);

        $this->actingAs($this->makeInstructor(), 'sanctum')
            ->post('/api/instructor/courses', $this->coursePayload([
                'thumbnail' => UploadedFile::fake()->image('thumb.png')->size(101),
            ]), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['thumbnail']);
    }

    public function test_lesson_video_mimes_and_configurable_max_size(): void
    {
        config(['uploads.video_max_kb' => 1000]);

        $instructor = $this->makeInstructor();
        $section = $this->makeSection($this->makeCourse($instructor, ['status' => CourseStatus::DRAFT]));
        $uri = "/api/instructor/sections/{$section->id}/lessons";

        $this->actingAs($instructor, 'sanctum');

        foreach ([
            UploadedFile::fake()->create('too-big.mp4', 1001, 'video/mp4'),
            UploadedFile::fake()->create('clip.avi', 100, 'video/x-msvideo'),
            UploadedFile::fake()->create('notes.txt', 1, 'text/plain'),
        ] as $file) {
            $this->post($uri, ['title' => 'Lesson', 'video' => $file], ['Accept' => 'application/json'])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['video']);
        }

        foreach ([
            UploadedFile::fake()->create('ok.mp4', 1000, 'video/mp4'),
            UploadedFile::fake()->create('ok.mov', 100, 'video/quicktime'),
            UploadedFile::fake()->create('ok.webm', 100, 'video/webm'),
        ] as $file) {
            $this->post($uri, ['title' => 'Lesson', 'video' => $file], ['Accept' => 'application/json'])
                ->assertCreated();
        }

        $this->assertSame(3, $section->lessons()->count());
    }
}
