<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Services\CourseProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LessonProgressController extends Controller
{
    public function __construct(
        private CourseProgressService $courseProgressService
    ) {
    }

    // Mark a lesson as completed (idempotent).
    public function complete(Request $request, int $lessonId): JsonResponse
    {
        return $this->setCompleted($request, $lessonId, true);
    }

    // Undo a completed lesson (idempotent).
    public function uncomplete(Request $request, int $lessonId): JsonResponse
    {
        return $this->setCompleted($request, $lessonId, false);
    }

    private function setCompleted(
        Request $request,
        int $lessonId,
        bool $completed
    ): JsonResponse {
        $lesson = Lesson::with('section')->find($lessonId);

        if (! $lesson) {
            return response()->json([
                'message' => 'Lesson not found.',
            ], 404);
        }

        $student = $request->user();
        $courseId = $lesson->section->course_id;

        $isEnrolled = $student->enrollments()
            ->where('course_id', $courseId)
            ->exists();

        if (! $isEnrolled) {
            return response()->json([
                'message' => 'You are not enrolled in this course.',
            ], 403);
        }

        if ($completed) {
            LessonProgress::firstOrCreate(
                [
                    'user_id' => $student->id,
                    'lesson_id' => $lesson->id,
                ],
                [
                    'completed_at' => now(),
                ]
            );
        } else {
            LessonProgress::query()
                ->where('user_id', $student->id)
                ->where('lesson_id', $lesson->id)
                ->delete();
        }

        return response()->json([
            'message' => $completed
                ? 'Lesson marked as completed.'
                : 'Lesson marked as not completed.',
            'data' => [
                'lesson_id' => $lesson->id,
                'completed' => $completed,
                'progress' => $this->courseProgressService->forCourse(
                    $student,
                    $courseId
                ),
            ],
        ]);
    }
}
