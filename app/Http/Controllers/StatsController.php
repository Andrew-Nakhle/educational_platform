<?php

namespace App\Http\Controllers;

use App\Enums\Course\CourseStatus;
use App\Enums\User\UserStatus;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;

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

    // Admin dashboard numbers. Every role and status is listed, with 0
    // when there are none.
    public function admin(): JsonResponse
    {
        $statuses = array_map(fn ($status) => $status->value, UserStatus::cases());

        $usersByRole = [];

        foreach (Role::query()->orderBy('name')->pluck('name') as $role) {
            $counts = User::role($role)
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $row = [];

            foreach ($statuses as $status) {
                $row[$status] = (int) ($counts[$status] ?? 0);
            }

            $row['total'] = array_sum($row);
            $usersByRole[$role] = $row;
        }

        $courseCounts = Course::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $coursesByStatus = [];

        foreach (CourseStatus::cases() as $status) {
            $coursesByStatus[$status->value] = (int) ($courseCounts[$status->value] ?? 0);
        }

        $coursesByStatus['total'] = array_sum($coursesByStatus);

        return response()->json([
            'message' => 'Admin statistics retrieved successfully.',
            'data' => [
                'users_by_role_and_status' => $usersByRole,
                'courses_by_status' => $coursesByStatus,

                // Same filter as GET /api/admin/instructor-requests.
                'pending_instructor_requests' => User::role('instructor')
                    ->where('status', UserStatus::PENDING)
                    ->count(),

                'pending_courses' => $coursesByStatus[CourseStatus::PENDING_PUBLISH->value],
            ],
        ]);
    }
}
