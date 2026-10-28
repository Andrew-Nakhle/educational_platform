<?php

namespace App\Http\Controllers;

use App\Enums\Course\VideoStatus;
use App\Http\Requests\Instructor\ReorderRequest;
use App\Http\Requests\Instructor\ReplaceLessonVideoRequest;
use App\Http\Requests\Instructor\StoreLessonRequest;
use App\Http\Requests\Instructor\UpdateLessonRequest;
use App\Http\Resources\Course\LessonResource;
use App\Jobs\ProcessLessonVideo;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\Section;
use App\Services\B2StorageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;
use App\Support\Redact;

class LessonController extends Controller
{
    public function __construct(
        private B2StorageService $b2StorageService
    ) {
    }
    public function createLesson(StoreLessonRequest $request, int $sectionId
    ) {
        $validated = $request->validated();

        $instructorProfile = $request->user()->instructorProfile;

        $section = Section::with('course')->findOrFail($sectionId);

        if ($section->course->instructor_profile_id !== $instructorProfile->id) {
            return response()->json([
                'message' => 'You are not authorized to add a lesson to this section.',
            ], 403);
        }

        if ($locked = $this->courseContentLocked($section->course)) {
            return $locked;
        }

        $sortOrder = $section->lessons()->count() + 1;

        $lesson = DB::transaction(function () use (
            $request,
            $validated,
            $section,
            $sortOrder
        ) {
            $lesson = $section->lessons()->create([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'duration_seconds' => 0,
                'sort_order' => $sortOrder,
            ]);

            $sourcePath = $request->file('video')->store(
                'lessons/source',
                config('filesystems.upload_disk')
            );

            $lessonVideo = $lesson->video()->create([
                'source_path' => $sourcePath,
                'playlist_path' => null,
                'duration_seconds' => 0,
                'status' => VideoStatus::PROCESSING,
            ]);

            ProcessLessonVideo::dispatch($lessonVideo->id)->afterCommit();

            return $lesson->load('video');
        });

        return response()->json([
            'message' => 'Lesson created successfully. Video processing started.',
            'lesson' => new LessonResource($lesson),
        ], 201);
    }


    // POST /instructor/lessons/{lessonId}/video (multipart, field: video)
    public function replaceVideo(ReplaceLessonVideoRequest $request, int $lessonId)
    {
        $lesson = Lesson::with('section.course', 'video')
            ->find($lessonId);

        if (! $lesson) {
            return response()->json([
                'message' => 'Lesson not found.',
            ], 404);
        }

        if (
            (int) $lesson->section->course->instructor_profile_id
            !== (int) $request->user()->instructorProfile?->id
        ) {
            return response()->json([
                'message' => 'You are not authorized to update this lesson.',
            ], 403);
        }

        if ($locked = $this->courseContentLocked($lesson->section->course)) {
            return $locked;
        }

        $oldVideo = $lesson->video;
        $oldSourcePath = $oldVideo?->source_path;
        $oldHlsDirectory = $oldVideo?->playlist_path
            ? dirname($oldVideo->playlist_path)
            : null;

        $sourcePath = $request->file('video')->store(
            'lessons/source',
            config('filesystems.upload_disk')
        );

        $lessonVideo = DB::transaction(function () use ($lesson, $sourcePath) {
            $lesson->update([
                'duration_seconds' => 0,
            ]);

            return $lesson->video()->updateOrCreate(
                ['lesson_id' => $lesson->id],
                [
                    'source_path' => $sourcePath,
                    'playlist_path' => null,
                    'duration_seconds' => 0,
                    'status' => VideoStatus::PROCESSING,
                ]
            );
        });

        /*
         * Remove the old files before dispatching: the job writes the new
         * HLS output to the same lessons/{id}/hls directory, and with the
         * sync queue it runs immediately.
         */
        try {
            if ($oldSourcePath && $oldSourcePath !== $sourcePath) {
                $this->b2StorageService->deleteAllB2Versions($oldSourcePath);
            }

            if ($oldHlsDirectory) {
                $this->b2StorageService->deleteAllB2Versions($oldHlsDirectory, true);
            }
        } catch (Throwable $e) {
            Log::warning('Failed to delete the old lesson video files.', [
                'lesson_id' => $lesson->id,
                'error' => Redact::secrets($e->getMessage()),
            ]);
        }

        ProcessLessonVideo::dispatch($lessonVideo->id);

        return response()->json([
            'message' => 'Lesson video replaced successfully. Video processing started.',
            'lesson' => new LessonResource($lesson->refresh()->load('video')),
        ]);
    }

    public function retryVideo(int $lessonVideoId)
    {
        $lessonVideo = LessonVideo::with('lesson.section.course')
            ->find($lessonVideoId);

        if (!$lessonVideo) {
            return response()->json([
                'message' => 'Lesson video not found.',
            ], 404);
        }
        $instructorProfile = request()->user()->instructorProfile;

        if (
            $lessonVideo->lesson->section->course->instructor_profile_id
            !== $instructorProfile->id
        ) {
            return response()->json([
                'message' => 'You are not authorized to retry this video.',
            ], 403);
        }

        if ($lessonVideo->status !== VideoStatus::FAILED) {
            return response()->json([
                'message' => 'Only failed videos can be retried.',
            ], 422);
        }

        $lessonVideo->update([
            'status' => VideoStatus::PROCESSING,
        ]);

        ProcessLessonVideo::dispatch($lessonVideo->id);

        return response()->json([
            'message' => 'Video processing restarted successfully.',
            'status' => $lessonVideo->status->value,
        ]);
    }


    public function getLessons(int $sectionId)
    {
        $section = Section::with('course')
            ->find($sectionId);

        if (!$section){
            return response()->json([
                'message' => 'Section not found.',
            ], 404);
        }

        $instructorProfile = request()->user()->instructorProfile;

        if (
            $section->course->instructor_profile_id
            !== $instructorProfile->id
        ) {
            return response()->json([
                'message' => 'You are not authorized to view these lessons.',
            ], 403);
        }

        $lessons = $section->lessons()
            ->with('video')
            ->orderBy('sort_order')
            ->get();

        return response()->json(LessonResource::collection($lessons)) ;
    }

    public function getLessonById(int $lessonId)
    {
        $lesson = Lesson::with('section.course', 'video')
            ->find($lessonId);
        if (!$lesson) {
            return response()->json([
                'message' => 'Lesson not found.',
            ], 404);
        }

        $instructorProfile = request()->user()->instructorProfile;

        if (
            $lesson->section->course->instructor_profile_id
            !== $instructorProfile->id
        ) {
            return response()->json([
                'message' => 'You are not authorized to view this lesson.',
            ], 403);
        }

        return response()->json([
            'lesson' => new LessonResource($lesson),
        ]);
    }


    public function updateLesson(UpdateLessonRequest $request, int $lessonId) {
        $validated = $request->validated();

        $lesson = Lesson::with('section.course')
            ->find($lessonId);

        if (!$lesson) {
            return response()->json([
                'message' => 'Lesson not found.',
            ], 404);
        }

        $instructorProfile = $request->user()->instructorProfile;

        if (
            $lesson->section->course->instructor_profile_id
            !== $instructorProfile->id
        ) {
            return response()->json([
                'message' => 'You are not authorized to update this lesson.',
            ], 403);
        }

        if ($locked = $this->courseContentLocked($lesson->section->course)) {
            return $locked;
        }

        $lesson->update($validated);

        return response()->json([
            'message' => 'Lesson updated successfully.',
            'lesson' => new LessonResource(
                $lesson->load('video')
            ),
        ]);
    }

//    private function deleteAllB2Versions(string $path): void
//    {
//        $disk = Storage::disk('b2');
//
//        $client = $disk->getClient();
//
//        $bucket = config('filesystems.disks.b2.bucket');
//
//        $versions = $client->listObjectVersions([
//            'Bucket' => $bucket,
//            'Prefix' => $path,
//        ]);
//
//        foreach ($versions['Versions'] ?? [] as $version) {
//
//            if ($version['Key'] !== $path) {
//                continue;
//            }
//
//            $client->deleteObject([
//                'Bucket' => $bucket,
//                'Key' => $version['Key'],
//                'VersionId' => $version['VersionId'],
//            ]);
//        }
//
//        foreach ($versions['DeleteMarkers'] ?? [] as $marker) {
//
//            if ($marker['Key'] !== $path) {
//                continue;
//            }
//
//            $client->deleteObject([
//                'Bucket' => $bucket,
//                'Key' => $marker['Key'],
//                'VersionId' => $marker['VersionId'],
//            ]);
//        }
//    }

    public function deleteLesson(int $lessonId)
    {
        $lesson = Lesson::with('section.course', 'video')
            ->findOrFail($lessonId);

        $instructorProfile = request()->user()->instructorProfile;

        if (
            $lesson->section->course->instructor_profile_id
            !== $instructorProfile->id
        ) {
            return response()->json([
                'message' => 'You are not authorized to delete this lesson.',
            ], 403);
        }

        if ($locked = $this->courseContentLocked($lesson->section->course)) {
            return $locked;
        }

        $lessonVideo = $lesson->video;

        if ($lessonVideo) {

            $disk = Storage::disk(config('filesystems.upload_disk'));

            /*
             * Delete original source video
             * including all its versions.
             */
            if ($lessonVideo->source_path) {
                $this->b2StorageService->deleteAllB2Versions(
                    $lessonVideo->source_path
                );
            }

            /*
             * Delete all HLS files
             * including all their versions.
             */
            if ($lessonVideo->playlist_path) {

                $hlsDirectory = dirname(
                    $lessonVideo->playlist_path
                );

                $files = $disk->allFiles($hlsDirectory);

                foreach ($files as $file) {
                    $this->b2StorageService->deleteAllB2Versions($file);
                }
            }
        }

        $lesson->delete();

        return response()->json([
            'message' => 'Lesson deleted successfully.',
        ]);
    }

    // PATCH /instructor/sections/{sectionId}/lessons/reorder { ids: [...] }
    public function reorderLessons(ReorderRequest $request, int $sectionId)
    {
        $section = Section::with(['course', 'lessons'])->find($sectionId);

        if (! $section) {
            return response()->json([
                'message' => 'Section not found.',
            ], 404);
        }

        if ((int) $section->course?->instructor_profile_id !== (int) $request->user()->instructorProfile?->id) {
            return response()->json([
                'message' => 'You are not authorized to reorder these lessons.',
            ], 403);
        }

        if ($locked = $this->courseContentLocked($section->course)) {
            return $locked;
        }

        $this->applySortOrder(
            $section->lessons,
            $request->validated()['ids'],
            'The ids must list every lesson of this section exactly once.'
        );

        return response()->json([
            'message' => 'Lessons reordered successfully.',
        ]);
    }
}
