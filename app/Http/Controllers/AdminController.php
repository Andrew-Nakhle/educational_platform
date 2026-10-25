<?php

namespace App\Http\Controllers;

use App\Enums\Course\CourseStatus;
use App\Enums\User\UserStatus;
use App\Http\Requests\Admin\GetCoursesRequest;
use App\Http\Requests\Admin\GetUsersRequest;
use App\Http\Requests\Admin\RejectRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Http\Resources\Auth\UserResource;
use App\Http\Resources\Course\CourseResource;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    //هاد التابع بدخلو الحالة والدور وبرجعلي المستخدمين حسب الحالة والدور مثلا قلتلو
    //بدي كل الاساتذة بدون ما حدد الدور وقتها بجبلي كل الادوار لهي الاساتذة وهكذا.....
    public function getAllUsers(GetUsersRequest $request)
    {
        $validated = $request->validated();

        $query = User::with([
            'roles',
            'studentProfile',
            'instructorProfile',
            'instructorProfile.certificates',
        ]);

        if (isset($validated['role'])) {
            $query->role($validated['role']);
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return UserResource::collection(
            $query->get()
        );
    }
    public function getUserById($UserId){
        $user=User::with(['studentProfile','instructorProfile','instructorProfile.certificates'])->findOrFail($UserId);
        return response()->json(new UserResource($user));
    }

    public function updateUserStatus(int $id, UpdateUserStatusRequest $request)
    {
        $validated = $request->validated();

        $user = User::findOrFail($id);

        $user->update([
            'status' => $validated['status'],
        ]);

        if ($user->status === UserStatus::ACTIVE) {
            $user->instructorProfile?->update([
                'reject_reason' => null,
            ]);
        }

        $user->load([
            'roles',
            'studentProfile',
            'instructorProfile',
        ]);

        return  response()->json(new UserResource($user));
    }

    public function getAllCourses(GetCoursesRequest $request)
    {
        $validated = $request->validated();

        $query = Course::with([
            'category',
            'instructorProfile',
        ])->withCount('enrollments');

        if (isset($validated['status'])) {
            $query->where(
                'status',
                $validated['status']
            );
        }

        if ($request->has('page')) {
            $courses = $query
                ->latest()
                ->latest('id')
                ->paginate((int) ($validated['per_page'] ?? 15));

            return response()->json([
                'message' => 'Courses retrieved successfully.',
                'data' => CourseResource::collection($courses),
                'meta' => $this->paginationMeta($courses),
            ]);
        }

        return response()->json([
            'courses' => CourseResource::collection(
                $query->get()
            ),
        ]);
    }

    public function getCourseById(int $courseId)
    {
        $course = Course::with([
            'category',
            'instructorProfile',
            'sections.lessons.video',
        ])->withCount('enrollments')->findOrFail($courseId);

        return response()->json([
            'course' => new CourseResource($course),
        ]);
    }

    public function approveCourse(int $courseId)
    {
        $course = Course::find($courseId);
if (!$course) {
    return response()->json([
        'message' => 'Course not found',
    ], 404);
}

        if ($course->status !== CourseStatus::PENDING_PUBLISH) {
            return response()->json([
                'message' => 'Only courses pending publication can be approved.',
            ], 422);
        }

        $course->update([
            'status' => CourseStatus::PUBLISHED,
            'reject_reason' => null,
        ]);

        return response()->json([
            'message' => 'Course approved and published successfully.',
            'status' => $course->status->value,
        ]);
    }

    public function rejectCourse(int $courseId, RejectRequest $request)
    {
        $course = Course::find($courseId);
        if (!$course) {
            return response()->json([
                'message' => 'Course not found',
            ], 404);
        }

        if ($course->status !== CourseStatus::PENDING_PUBLISH) {
            return response()->json([
                'message' => 'Only courses pending publication can be rejected.',
            ], 422);
        }

        $course->update([
            'status' => CourseStatus::REJECTED,
            'reject_reason' => $request->validated()['reason'] ?? null,
        ]);

        return response()->json([
            'message' => 'Course rejected successfully.',
            'status' => $course->status->value,
        ]);
    }

    /////////////////////////
    ///
// عرض طلبات الأساتذة بانتظار الموافقة
    public function getInstructorRequests()
    {
        $users = User::query()
            ->whereHas('roles', fn ($query) =>
            $query->where('name', 'instructor')
            )
            ->where('status', UserStatus::PENDING)
            ->with(['instructorProfile.certificates', 'roles'])
            ->latest()
            ->paginate(15);

        return UserResource::collection($users);
    }

// عرض تفاصيل طلب أستاذ
    public function getInstructorRequest(int $userId)
    {
        $user = User::query()
            ->whereHas('roles', fn ($query) =>
            $query->where('name', 'instructor')
            )
            ->where('status', UserStatus::PENDING)
            ->with(['instructorProfile.certificates', 'roles'])
            ->findOrFail($userId);

        return response()->json([
            'data' => new UserResource($user),
        ]);
    }

// قبول طلب أستاذ
    public function approveInstructor(int $userId)
    {
        $user = User::query()
            ->whereHas('roles', fn ($query) =>
            $query->where('name', 'instructor')
            )
            ->where('status', UserStatus::PENDING)
            ->with(['instructorProfile.certificates', 'roles'])
            ->findOrFail($userId);

        DB::transaction(function () use ($user) {
            $user->update([
                'status' => UserStatus::ACTIVE,
            ]);

            $user->instructorProfile?->update([
                'reject_reason' => null,
            ]);
        });

        return response()->json([
            'message' => 'Instructor approved successfully.',
            'instructor' => new UserResource(
                $user->refresh()->load([
                    'instructorProfile.certificates',
                    'roles',
                ])
            ),
        ]);
    }

// رفض طلب أستاذ
    public function rejectInstructor(int $userId, RejectRequest $request)
    {
        $user = User::query()
            ->whereHas('roles', fn ($query) =>
            $query->where('name', 'instructor')
            )
            ->where('status', UserStatus::PENDING)
            ->findOrFail($userId);

        DB::transaction(function () use ($user, $request) {
            $user->update([
                'status' => UserStatus::INACTIVE,
            ]);

            $user->instructorProfile?->update([
                'reject_reason' => $request->validated()['reason'] ?? null,
            ]);
        });

        return response()->json([
            'message' => 'Instructor application rejected successfully.',
        ]);
    }




}
