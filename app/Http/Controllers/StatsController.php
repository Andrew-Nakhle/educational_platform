<?php

namespace App\Http\Controllers;

use App\Enums\Course\CourseStatus;
use App\Enums\User\UserStatus;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class StatsController extends Controller
{
    // Public platform numbers for the landing page.
    public function index(): JsonResponse
    {
        return response()->json([
            'message' => 'Statistics retrieved successfully.',
            'data' => [
                // Students that have not deleted their account.
                'students' => User::role('student')
                    ->where('status', '!=', UserStatus::DELETED)
                    ->count(),

                'courses' => Course::where('status', CourseStatus::PUBLISHED)->count(),

                'instructors' => User::role('instructor')
                    ->where('status', UserStatus::ACTIVE)
                    ->count(),

                // Lessons of published courses.
                'lessons' => Lesson::whereHas(
                    'section.course',
                    fn ($query) => $query->where('status', CourseStatus::PUBLISHED)
                )->count(),
            ],
        ]);
    }
}
