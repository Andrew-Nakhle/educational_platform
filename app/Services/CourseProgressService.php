<?php

namespace App\Services;

use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;

class CourseProgressService
{
    /**
     * IDs of the lessons of a course the user has completed.
     *
     * @return array<int, int>
     */
    public function completedLessonIds(User $user, int $courseId): array
    {
        return LessonProgress::query()
            ->where('user_id', $user->id)
            ->whereIn('lesson_id', $this->courseLessonIds($courseId))
            ->pluck('lesson_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Progress of a user in one course.
     *
     * @return array{course_id: int, completed_lessons: int, total_lessons: int, percent: int}
     */
    public function forCourse(User $user, int $courseId): array
    {
        $total = $this->courseLessonIds($courseId)->count();
        $completed = count($this->completedLessonIds($user, $courseId));

        return [
            'course_id' => $courseId,
            'completed_lessons' => $completed,
            'total_lessons' => $total,
            'percent' => $this->percent($completed, $total),
        ];
    }

    /**
     * Progress and last completed lesson for several courses at once.
     *
     * @param  array<int, int>  $courseIds
     * @return array<int, array{last_lesson_id: ?int, progress: array{completed_lessons: int, total_lessons: int, percent: int}}>
     */
    public function forCourses(User $user, array $courseIds): array
    {
        $lessons = Lesson::query()
            ->join('sections', 'sections.id', '=', 'lessons.section_id')
            ->whereIn('sections.course_id', $courseIds)
            ->get(['lessons.id', 'sections.course_id']);

        $courseByLesson = $lessons->pluck('course_id', 'id');

        $completions = LessonProgress::query()
            ->where('user_id', $user->id)
            ->whereIn('lesson_id', $courseByLesson->keys())
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get(['lesson_id']);

        $result = [];

        foreach ($courseIds as $courseId) {
            $courseCompletions = $completions->filter(
                fn ($progress) => (int) $courseByLesson[$progress->lesson_id] === (int) $courseId
            );

            $total = $courseByLesson->filter(
                fn ($id) => (int) $id === (int) $courseId
            )->count();

            $completed = $courseCompletions->count();

            $result[$courseId] = [
                'last_lesson_id' => $courseCompletions->last()?->lesson_id,
                'progress' => [
                    'completed_lessons' => $completed,
                    'total_lessons' => $total,
                    'percent' => $this->percent($completed, $total),
                ],
            ];
        }

        return $result;
    }

    private function courseLessonIds(int $courseId)
    {
        return Lesson::query()
            ->whereHas('section', fn ($query) => $query->where('course_id', $courseId))
            ->pluck('id');
    }

    private function percent(int $completed, int $total): int
    {
        return $total === 0
            ? 0
            : (int) round($completed * 100 / $total);
    }
}
