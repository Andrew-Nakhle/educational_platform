<?php

namespace App\Http\Controllers;

use App\Enums\Course\CourseStatus;
use App\Enums\User\UserStatus;
use App\Http\Requests\Admin\GetCoursesRequest;
use App\Http\Requests\Admin\GetPaymentsRequest;
use App\Http\Requests\Admin\GetUsersRequest;
use App\Http\Requests\Admin\RejectRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Http\Resources\Admin\PaymentResource;
use App\Http\Resources\Auth\UserResource;
use App\Http\Resources\Course\CourseResource;
use App\Models\Course;
use App\Models\InstructorProfile;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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

        if (filled($validated['search'] ?? null)) {
            $this->applyUserSearch($query, $validated['search']);
        }

        // With ?page= the response is the paginated {data, links, meta};
        // without it, the plain list as before.
        if ($request->has('page')) {
            return UserResource::collection(
                $query->latest()
                    ->latest('id')
                    ->paginate((int) ($validated['per_page'] ?? 15))
                    ->withQueryString()
            );
        }

        return UserResource::collection(
            $query->get()
        );
    }

    /**
     * ?search=: every word must match the email or a name part (first,
     * father or last name of the student or instructor profile),
     * case-insensitively. LOWER() keeps it case-insensitive on PostgreSQL
     * too; % and _ in the input are matched literally (escaped with "!",
     * which, unlike a backslash, means the same in SQLite, MySQL and
     * PostgreSQL string literals).
     */
    private function applyUserSearch(Builder $query, string $search): void
    {
        $words = preg_split('/\s+/', mb_strtolower(trim($search)), -1, PREG_SPLIT_NO_EMPTY);

        foreach ($words as $word) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';
            $like = fn ($q, string $column) => $q->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);
            $names = fn ($profile) => $profile->where(fn ($q) => collect(['first_name', 'father_name', 'last_name'])
                ->each(fn ($column) => $like($q, $column)));

            $query->where(fn ($q) => $like($q, 'users.email')
                ->orWhereHas('studentProfile', $names)
                ->orWhereHas('instructorProfile', $names));
        }
    }

    // GET /admin/payments, newest first; ?page= for {data, links, meta}.
    public function getAllPayments(GetPaymentsRequest $request)
    {
        $validated = $request->validated();

        $query = Payment::with([
            'user.studentProfile',
            'course',
        ])->latest()->latest('id');

        if ($request->has('page')) {
            return PaymentResource::collection(
                $query->paginate((int) ($validated['per_page'] ?? 15))
                    ->withQueryString()
            );
        }

        return PaymentResource::collection(
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
            'course' => (new CourseResource($course))->withRejectReason(),
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

        if (! $course->hasLessons()) {
            return response()->json([
                'message' => 'A course with no lessons cannot be approved.',
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

            // Without a reason, a default one: reject_reason is what marks a
            // rejected application (as opposed to a deactivated account).
            $user->instructorProfile?->update([
                'reject_reason' => filled($request->validated()['reason'] ?? null)
                    ? $request->validated()['reason']
                    : InstructorProfile::DEFAULT_REJECT_REASON,
            ]);
        });

        return response()->json([
            'message' => 'Instructor application rejected successfully.',
        ]);
    }




}
