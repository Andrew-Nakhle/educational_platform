<?php

namespace App\Http\Controllers;

use App\Http\Requests\Instructor\ReorderRequest;
use App\Http\Requests\Instructor\StoreSectionRequest;
use App\Http\Requests\Instructor\UpdateSectionRequest;
use App\Models\Course;
use App\Models\Section;
use App\Services\B2StorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;
use App\Support\Redact;

class SectionController extends Controller
{
    public function __construct(
        private B2StorageService $b2StorageService
    ) {
    }
    public function createSection(StoreSectionRequest $request, int $courseId) {
        $validated = $request->validated();

        $course = Course::find($courseId);
        if (!$course) {
            return response()->json(['message' => 'Course not found'], 404);
        }

        $instructorProfile = $request->user()->instructorProfile;

        if ($course->instructor_profile_id !== $instructorProfile->id) {
            return response()->json([
                'message' => 'You are not authorized to manage this course.',
            ], 403);
        }

        if ($locked = $this->courseContentLocked($course)) {
            return $locked;
        }

        $sortOrder = $course->sections()->count() + 1;

        $section = $course->sections()->create([
            'title' => $validated['title'],
            'sort_order' => $sortOrder,
        ]);

        return response()->json([
            'message' => 'Section created successfully.',
            'section' => $section,
        ], 201);
    }

    public function getSections(int $courseId)
    {
        $course = Course::with('sections')
            ->find($courseId);
        if (!$course) {
            return response()->json(['message' => 'section not found'], 404);
        }

        $instructorProfile = request()->user()->instructorProfile;

        if (
            $course->instructor_profile_id
            !== $instructorProfile->id
        ) {
            return response()->json([
                'message' => 'You are not authorized to view these sections.',
            ], 403);
        }

        return response()->json([
            'sections' => $course->sections()
                ->orderBy('sort_order')
                ->get(),
        ]);
    }

    public function getSectionById(int $sectionId)
    {
        $section =Section::with('course')
            ->find($sectionId);
        if (!$section) {
            return response()->json(['message' => 'Section not found'], 404);
        }

        $instructorProfile = request()->user()->instructorProfile;

        if (
            $section->course->instructor_profile_id
            !== $instructorProfile->id
        ) {
            return response()->json([
                'message' => 'You are not authorized to view this section.',
            ], 403);
        }

        return response()->json([
            'section' => $section,
        ]);
    }

    public function updateSection(UpdateSectionRequest $request, int $sectionId) {
        $validated = $request->validated();

        $section =Section::with('course')->find($sectionId);

        if (!$section) {return response()->json(['message' => 'Section not found.',], 404);}
        $instructorProfile = $request->user()->instructorProfile;

        if ($section->course->instructor_profile_id !== $instructorProfile->id) {

            return response()->json(['message' => 'You are not authorized to update this section.',], 403);
        }

        if ($locked = $this->courseContentLocked($section->course)) {
            return $locked;
        }

        $section->update($validated);

        return response()->json([
            'message' => 'Section updated successfully.',
            'section' => $section,
        ]);
    }

//    private function deleteAllB2Versions(
//        string $path,
//        bool $isDirectory = false
//    ): void {
//        $disk = Storage::disk('b2');
//
//        $client = $disk->getClient();
//
//        $bucket = config('filesystems.disks.b2.bucket');
//
//        $keyMarker = null;
//        $versionIdMarker = null;
//
//        do {
//
//            $params = [
//                'Bucket' => $bucket,
//                'Prefix' => $path,
//            ];
//
//            if ($keyMarker !== null) {
//                $params['KeyMarker'] = $keyMarker;
//            }
//
//            if ($versionIdMarker !== null) {
//                $params['VersionIdMarker'] = $versionIdMarker;
//            }
//
//            $result = $client->listObjectVersions($params);
//
//            $objectsToDelete = [];
//
//            /*
//             * Normal object versions.
//             */
//            foreach ($result['Versions'] ?? [] as $version) {
//
//                if (
//                    ! $isDirectory
//                    && $version['Key'] !== $path
//                ) {
//                    continue;
//                }
//
//                $objectsToDelete[] = [
//                    'Key' => $version['Key'],
//                    'VersionId' => $version['VersionId'],
//                ];
//            }
//
//            /*
//             * Delete markers.
//             */
//            foreach ($result['DeleteMarkers'] ?? [] as $marker) {
//
//                if (
//                    ! $isDirectory
//                    && $marker['Key'] !== $path
//                ) {
//                    continue;
//                }
//
//                $objectsToDelete[] = [
//                    'Key' => $marker['Key'],
//                    'VersionId' => $marker['VersionId'],
//                ];
//            }
//
//            /*
//             * Delete versions in batches.
//             */
//            if (! empty($objectsToDelete)) {
//
//                foreach (array_chunk($objectsToDelete, 1000) as $chunk) {
//
//                    $client->deleteObjects([
//                        'Bucket' => $bucket,
//                        'Delete' => [
//                            'Objects' => $chunk,
//                            'Quiet' => true,
//                        ],
//                    ]);
//                }
//            }
//
//            $keyMarker = $result['NextKeyMarker'] ?? null;
//            $versionIdMarker = $result['NextVersionIdMarker'] ?? null;
//
//            $isTruncated = $result['IsTruncated'] ?? false;
//
//        } while ($isTruncated);
//    }
    public function deleteSection(int $sectionId)
    {
        $section = Section::with([
            'course',
            'lessons.video',
        ])->findOrFail($sectionId);

        $instructorProfile = request()->user()->instructorProfile;

        /*
         * Check ownership.
         */
        if (
            $section->course->instructor_profile_id
            !== $instructorProfile->id
        ) {
            return response()->json([
                'message' => 'You are not authorized to delete this section.',
            ], 403);
        }

        if ($locked = $this->courseContentLocked($section->course)) {
            return $locked;
        }



        try {

            /*
             * Delete all videos and HLS files from B2
             * before deleting the section from database.
             */
            foreach ($section->lessons as $lesson) {

                $lessonVideo = $lesson->video;

                if (! $lessonVideo) {
                    continue;
                }

                /*
                 * Delete original source video
                 * including all B2 versions.
                 */
                if ($lessonVideo->source_path) {
                    $this->b2StorageService->deleteAllB2Versions(   $lessonVideo->source_path);
                }

                /*
                 * Delete all HLS files
                 * including all B2 versions.
                 */
                if ($lessonVideo->playlist_path) {

                    $hlsDirectory = dirname(
                        $lessonVideo->playlist_path
                    );

                    $this->b2StorageService->deleteAllB2Versions(
                        $hlsDirectory,
                        true
                    );
                }
            }

            /*
             * Delete section from database.
             *
             * Lessons will be deleted automatically
             * because of cascadeOnDelete().
             *
             * LessonVideo will also be deleted automatically
             * because of cascadeOnDelete().
             */
            $section->delete();

        } catch (Throwable $e) {

            return response()->json([
                'message' => 'Failed to delete section files. The section was not deleted.',
                'error' => Redact::secrets($e->getMessage()),
            ], 500);
        }

        return response()->json([
            'message' => 'Section deleted successfully.',
        ]);
    }

    // PATCH /instructor/courses/{courseId}/sections/reorder { ids: [...] }
    public function reorderSections(ReorderRequest $request, int $courseId)
    {
        $course = Course::with('sections')->find($courseId);

        if (! $course) {
            return response()->json([
                'message' => 'Course not found.',
            ], 404);
        }

        if ((int) $course->instructor_profile_id !== (int) $request->user()->instructorProfile?->id) {
            return response()->json([
                'message' => 'You are not authorized to reorder these sections.',
            ], 403);
        }

        if ($locked = $this->courseContentLocked($course)) {
            return $locked;
        }

        $this->applySortOrder(
            $course->sections,
            $request->validated()['ids'],
            'The ids must list every section of this course exactly once.'
        );

        return response()->json([
            'message' => 'Sections reordered successfully.',
        ]);
    }
}
