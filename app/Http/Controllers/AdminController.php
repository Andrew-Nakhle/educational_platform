<?php

namespace App\Http\Controllers;

use App\Enums\Course\CourseStatus;
use App\Http\Requests\Admin\GetCoursesRequest;
use App\Http\Requests\Admin\GetUsersRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Http\Resources\Auth\UserResource;
use App\Http\Resources\Course\CourseResource;
use App\Models\Course;
use App\Models\User;

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
        $user=User::with(['studentProfile','instructorProfile','instructorProfile.certificates'])->find($UserId);
        return response()->json(new UserResource($user));
    }

    public function updateUserStatus(int $id, UpdateUserStatusRequest $request)
    {
        $validated = $request->validated();

        $user = User::findOrFail($id);

        $user->update([
            'status' => $validated['status'],
        ]);

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
        ]);

        if (isset($validated['status'])) {
            $query->where(
                'status',
                $validated['status']
            );
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
        ])->findOrFail($courseId);

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
    ]);
}

        if ($course->status !== CourseStatus::PENDING_PUBLISH) {
            return response()->json([
                'message' => 'Only courses pending publication can be approved.',
            ], 422);
        }

        $course->update([
            'status' => CourseStatus::PUBLISHED,
        ]);

        return response()->json([
            'message' => 'Course approved and published successfully.',
            'status' => $course->status->value,
        ]);
    }

    public function rejectCourse(int $courseId)
    {
        $course = Course::find($courseId);
        if (!$course) {
            return response()->json([
                'message' => 'Course not found',
            ]);
        }

        if ($course->status !== CourseStatus::PENDING_PUBLISH) {
            return response()->json([
                'message' => 'Only courses pending publication can be rejected.',
            ], 422);
        }

        $course->update([
            'status' => CourseStatus::REJECTED,
        ]);

        return response()->json([
            'message' => 'Course rejected successfully.',
            'status' => $course->status->value,
        ]);
    }
}
