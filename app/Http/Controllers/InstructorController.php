<?php

namespace App\Http\Controllers;

use App\Http\Requests\Instructor\UpdateInstructorProfileRequest;
use App\Http\Resources\Auth\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InstructorController extends Controller
{
    public function updateProfile(UpdateInstructorProfileRequest $request): JsonResponse {
        $validated = $request->validated();

        $user = DB::transaction(function () use ($request, $validated) {

            $user = $request->user();

            $user->update([
                'phone' => $validated['phone'],
                'gender' => $validated['gender'],
                'birthday' => $validated['birthday'],
            ]);

            $profileData = [
                'first_name' => $validated['first_name'],
                'father_name' => $validated['father_name'],
                'last_name' => $validated['last_name'],
                'teaching_major' => $validated['teaching_major'],
                'bio' => $validated['bio'] ?? null,
            ];

            if ($request->hasFile('avatar')) {
                $profileData['avatar'] = $request->file('avatar')
                    ->store('instructors/avatars', config('filesystems.upload_disk'));
            }

            $user->instructorProfile()->updateOrCreate(
                [
                    'user_id' => $user->id,
                ],
                $profileData
            );

            return $user->load([
                'roles',
                'instructorProfile.certificates',
            ]);
        });

        $resource = new UserResource($user);

        return response()->json([
            'message' => 'Instructor profile updated successfully.',
            'user' => $resource,
            'data' => $resource,
        ]);
    }


}
