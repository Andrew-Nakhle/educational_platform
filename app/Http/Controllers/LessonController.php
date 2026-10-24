<?php

namespace App\Http\Controllers;

use App\Enums\Course\VideoStatus;
use App\Http\Requests\Instructor\StoreLessonRequest;
use App\Http\Requests\Instructor\UpdateLessonRequest;
use App\Http\Resources\Course\LessonResource;
use App\Jobs\ProcessLessonVideo;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\Section;
use App\Services\B2StorageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
                'b2'
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


    public function retryVideo(int $lessonVideoId)
    {
        $lessonVideo = LessonVideo::with('lesson.section.course')
            ->find($lessonVideoId);

        if (!$lessonVideo) {
            return response()->json([
                'message' => 'Lesson video not found.',
            ]);
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
            ]);
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
            ]);
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
            ]);
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

        $lessonVideo = $lesson->video;

        if ($lessonVideo) {

            $disk = Storage::disk('b2');

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


}
