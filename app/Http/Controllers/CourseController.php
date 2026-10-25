<?php

namespace App\Http\Controllers;

use App\Enums\Course\CourseStatus;
use App\Http\Requests\Instructor\GetCoursesRequest;
use App\Http\Requests\Instructor\StoreCourseRequest;
use App\Http\Requests\Instructor\UpdateCourseRequest;
use App\Http\Resources\Course\CourseResource;
use App\Models\Course;
use App\Services\B2StorageService;
use Illuminate\Support\Facades\DB;
use Throwable;

class CourseController extends Controller
{


    public function __construct(
        private B2StorageService $b2StorageService
    ) {
    }


    public function createCourse(StoreCourseRequest $request)
    {
        $validated = $request->validated();

        $course = DB::transaction(function () use ($request, $validated) {

            $instructorProfile = $request->user()->instructorProfile;

            $thumbnailPath = $request->file('thumbnail')
                ->store('courses/thumbnails', config('filesystems.upload_disk'));

            return $instructorProfile->courses()->create([
                'category_id' => $validated['category_id'],
                'title' => $validated['title'],
                'description' => $validated['description'],
                'price' => $validated['price'],
                'thumbnail' => $thumbnailPath,
                'language' => $validated['language'],
                'difficulty_level' => $validated['difficulty_level'],
                'what_you_will_learn' => $validated['what_you_will_learn'],
//                'duration_hours' => $validated['duration_hours'],
                'status' => 'draft',
            ]);
        });

        return response()->json([
            'message' => 'Course created successfully.',
            'course' => new CourseResource(
                $course->load([
                    'category',
                    'instructorProfile',
                ])
            ),
        ], 201);
    }
    public function getCourses(GetCoursesRequest $request)
    {
        $validated = $request->validated();

        $instructor = auth()->user()->instructorProfile;

        $query = $instructor->courses()->withCount('enrollments');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if ($request->has('page')) {
            $courses = $query
                ->latest()
                ->latest('id')
                ->paginate((int) ($validated['per_page'] ?? 15));

            return response()->json([
                'message' => 'Courses retrieved successfully.',
                'data' => CourseResource::ownedCollection($courses),
                'meta' => $this->paginationMeta($courses),
            ]);
        }

        return response()->json([
            'courses' => CourseResource::ownedCollection(
                $query->get()
            ),
        ]);
    }
    public function getCourseById($courseId){
        $course=Course::withCount('enrollments')->find($courseId);
        $instructor=auth()->user()->instructorProfile;

        if(!$course){
            return response()->json([
                'message' => 'Course not found.',
            ], 404);
        }
        if ($instructor->id != $course->instructorProfile->id) {
            return response()->json([
                'message' => 'You do not have permission to access this page.',
            ],403);
        }
        return response()->json([
            'course' => (new CourseResource($course))->withRejectReason()
        ]);

    }

    public function updateCourse(
        UpdateCourseRequest $request,
        int $courseId
    ) {
        $validated = $request->validated();

        $course = Course::find($courseId);

        if (! $course) {
            return response()->json([
                'message' => 'Course not found.',
            ], 404);
        }

        $instructor = $request->user()->instructorProfile;

        if (
            $course->instructor_profile_id
            !== $instructor->id
        ) {
            return response()->json([
                'message' => 'You are not authorized to update this course.',
            ], 403);
        }

        if (! $course->isEditable()) {
            return response()->json([
                'message' => 'Only draft or rejected courses can be updated.',
            ], 422);
        }

        /*
         * Keep the old thumbnail path.
         */
        $oldThumbnail = $course->thumbnail;

        /*
         * Upload new thumbnail if provided.
         */
        if ($request->hasFile('thumbnail')) {

            $newThumbnail = $request->file('thumbnail')
                ->store('courses/thumbnails', config('filesystems.upload_disk'));

            $validated['thumbnail'] = $newThumbnail;
        }

        /*
         * Update course.
         */
        $course->update($validated);

        /*
         * Delete old thumbnail only after
         * the new thumbnail was uploaded and
         * the course was updated successfully.
         */
        if (
            isset($newThumbnail)
            && $oldThumbnail
            && $oldThumbnail !== $newThumbnail
        ) {
            $this->b2StorageService->deleteAllB2Versions($oldThumbnail);
        }

        return response()->json([
            'message' => 'Course updated successfully.',
            'course' => new CourseResource(
                $course->load([
                    'category',
                    'instructorProfile',
                ])
            ),
        ]);
    }
    public function deleteCourse(int $courseId)
    {
        $course = Course::with([
            'sections.lessons.video',
        ])->findOrFail($courseId);

        $instructorProfile = request()->user()->instructorProfile;

        if (
            $course->instructor_profile_id
            !== $instructorProfile->id
        ) {
            return response()->json([
                'message' => 'You are not authorized to delete this course.',
            ], 403);
        }

        if ($course->status !== CourseStatus::DRAFT) {
            return response()->json([
                'message' => 'Only draft courses can be deleted.',
            ], 422);
        }

        try {

            foreach ($course->sections as $section) {

                foreach ($section->lessons as $lesson) {

                    $lessonVideo = $lesson->video;

                    if (! $lessonVideo) {
                        continue;
                    }

                    // Delete source video
                    if ($lessonVideo->source_path) {
                        $this->b2StorageService->deleteAllB2Versions(
                            $lessonVideo->source_path
                        );
                    }

                    // Delete HLS directory
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
            }

            // Delete course thumbnail
            if ($course->thumbnail) {
                $this->b2StorageService->deleteAllB2Versions(
                    $course->thumbnail
                );
            }

            // Cascade deletes:
            // Sections → Lessons → LessonVideos
            $course->delete();

        } catch (Throwable $e) {

            return response()->json([
                'message' => 'Failed to delete course files. The course was not deleted.',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'Course deleted successfully.',
        ]);
    }

    /////////////////////////////////////////////////
    ///
    ///
    /// andrew was here///////////////////////
    ///
    ///
    ///
    public function submitCourse(int $courseId)
    {
        $course = Course::find($courseId);
if (! $course) {
    return response()->json([
        'message' => 'Course not found.',
    ], 404);
}
        $instructorProfile = request()->user()->instructorProfile;

        if (
            $course->instructor_profile_id
            !== $instructorProfile->id
        ) {
            return response()->json([
                'message' => 'You are not authorized to submit this course.',
            ], 403);
        }

        if (! in_array($course->status, [CourseStatus::DRAFT, CourseStatus::REJECTED], true)) {
            return response()->json([
                'message' => 'Only draft or rejected courses can be submitted for publishing.',
            ], 422);
        }

        // Resubmitting a rejected course clears the previous reason.
        $course->update([
            'status' => CourseStatus::PENDING_PUBLISH,
            'reject_reason' => null,
        ]);

        return response()->json([
            'message' => 'Course submitted for publishing successfully.',
            'status' => $course->status->value,

        ]);
    }

    //ارشفة كورس
    public function archiveCourse(int $courseId)
    {
        $instructor = auth()->user()->instructorProfile;

        $course = $instructor->courses()
            ->find($courseId);

        if (! $course) {
            return response()->json([
                'message' => 'Course not found.',
            ], 404);
        }

        if ($course->status === CourseStatus::ARCHIVED) {
            return response()->json([
                'message' => 'Course is already archived.',
            ], 422);
        }

        $course->update([
            'status' => CourseStatus::ARCHIVED,
        ]);

        return response()->json([
            'message' => 'Course archived successfully.',
            'status' => $course->status->value,
        ]);
    }
    //ازلة ارشفة كورس وترجيعو لحالة ال draft
    public function restoreCourse(int $courseId)
    {
        $instructor = auth()->user()->instructorProfile;

        $course = $instructor->courses()
            ->find($courseId);

        if (! $course) {
            return response()->json([
                'message' => 'Course not found.',
            ], 404);
        }

        if ($course->status !== CourseStatus::ARCHIVED) {
            return response()->json([
                'message' => 'Only archived courses can be restored.',
            ], 422);
        }

        $course->update([
            'status' => CourseStatus::DRAFT,
        ]);

        return response()->json([
            'message' => 'Course restored successfully.',
            'status' => $course->status->value,
        ]);
    }

}
