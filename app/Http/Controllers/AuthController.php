<?php

namespace App\Http\Controllers;

use App\Enums\User\UserStatus;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterInstructorRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\Auth\UserResource;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Http\Requests\Auth\ChangePasswordRequest;
class AuthController extends Controller
{
    public function registerStudent(RegisterRequest $request)
    {
        $validated = $request->validated();

        $existingUser = User::where('email', $validated['email'])->first();

        if ($existingUser) {

            // Blocked
            if ($existingUser->status === UserStatus::BLOCKED) {
                return response()->json([
                    'message' => 'Your account is blocked and you cannot perform this action.',
                ], 403);
            }

            // Active / Inactive
            if (
                $existingUser->status === UserStatus::ACTIVE ||
                $existingUser->status === UserStatus::INACTIVE
            ) {
                return response()->json([
                    'message' => 'This email is already registered.',
                ], 422);
            }
        }


        if ($request->hasFile('avatar')) {
            $validated['avatar'] = $request->file('avatar')
                ->store('students/avatars', 'b2');
        }
        $result = DB::transaction(function () use ($validated, $existingUser) {

            // Deleted account → reuse it
            if ($existingUser?->status === UserStatus::DELETED) {

                $user = $existingUser;

                $user->update([
                    'phone' => $validated['phone'],
                    'gender' => $validated['gender'],
                    'birthday' => $validated['birthday'],
                    'password' => Hash::make($validated['password']),
                    'status' => UserStatus::ACTIVE,
                ]);

                $user->studentProfile()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                    ],
                    [
                        'first_name' => $validated['first_name'],
                        'father_name' => $validated['father_name'],
                        'last_name' => $validated['last_name'],
                        'avatar' => $validated['avatar'] ?? null,
                    ]
                );

            } else {

                // New account
                $user = User::create([
                    'email' => $validated['email'],
                    'phone' => $validated['phone'],
                    'gender' => $validated['gender'],
                    'birthday' => $validated['birthday'],
                    'password' => Hash::make($validated['password']),
                    'status' => UserStatus::ACTIVE,
                ]);

                $user->assignRole('student');

                $user->studentProfile()->create([
                    'first_name' => $validated['first_name'],
                    'father_name' => $validated['father_name'],
                    'last_name' => $validated['last_name'],
                    'avatar' => $validated['avatar'] ?? null ,
                ]);
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            return [
                'user' => $user->load('studentProfile', 'roles'),
                'token' => $token,
            ];
        });

        return response()->json([
            'message' => 'Registration successful',
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
        ], 201);
    }

    public function registerInstructor(RegisterInstructorRequest $request)
    {
        $validated = $request->validated();

        $instructor = DB::transaction(function () use ($request, $validated) {

            // Create user
            $user = User::create([
                'gender' => $validated['gender'],
                'birthday' => $validated['birthday'],
                'phone' => $validated['phone'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'status' => UserStatus::PENDING,
            ]);

            // Assign instructor role
            $user->assignRole('instructor');

            // Upload avatar
            $avatarPath = null;

            if ($request->hasFile('avatar')) {
                $avatarPath = $request->file('avatar')
                    ->store('instructors/avatars', 'b2');
            }

            // Create instructor profile
            $profile = $user->instructorProfile()->create([
                'first_name' => $validated['first_name'],
                'father_name' => $validated['father_name'],
                'last_name' => $validated['last_name'],
                'avatar' => $avatarPath,
                'teaching_major' => $validated['teaching_major'],
                'bio' => $validated['bio'] ?? null,
            ]);

            // Upload certifications
            foreach ($request->file('certifications', []) as $certificate) {
                $certificatePath = $certificate->store(
                    'instructors/certificates',
                    'b2'
                );

                $profile->certificates()->create([
                    'certification' => $certificatePath,
                ]);
            }
//هون عملت ريفريش كرمال ما يعطيني خطا انو حالة المستخدم null لهيك عملت هيك
            return $user->refresh()->load(['instructorProfile.certificates', 'roles',]);
        });
        return response()->json([
            'message' => 'Your instructor registration is pending admin approval.',
            'instructor' => new UserResource($instructor),
        ], 201);
    }


    public function login(LoginRequest $request)
    {
        $validated = $request->validated();

        if (! Auth::attempt(['email' => $validated['email'], 'password' => $validated['password'],])) {
            return response()->json([
                'message' => 'The email or password is incorrect.',
            ], 401);
        }

        $user = Auth::user();

        if ($user->status === UserStatus::BLOCKED) {
            Auth::logout();

            return response()->json([
                'message' => 'Your account has been blocked from the system.',
            ], 403);
        }

        if ($user->status === UserStatus::DELETED) {
            Auth::logout();

            return response()->json([
                'message' => 'This account no longer exists.',
            ], 403);
        }
        if ($user->status === UserStatus::PENDING) {
            return response()->json([
                'message' => 'Your account is pending admin approval.',
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'user' => new UserResource($user->load('roles','studentProfile',
                'instructorProfile.certificates')),
            'token' => $token,
        ]);
    }

    public function logout()
    {
        auth()->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logout Successful'], 200);
    }

    public function profile()
    {
        $user = auth()->user();
        return response()->json(new UserResource($user));
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_THROTTLED) {
            return response()->json([
                'message' => 'Please wait before requesting another reset link.',
            ], 429);
        }

        // Avoid revealing whether an email exists.
        return response()->json([
            'message' => 'If the email is registered, a password reset link will be sent.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $data,
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Revoke existing Sanctum tokens after a password reset.
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [
                    __($status),
                ],
            ]);
        }

        return response()->json([
            'message' => 'Password has been reset successfully.',
        ]);
    }


    public function changePassword(ChangePasswordRequest $request)
    {
        $user = $request->user();

        if (! Hash::check(
            $request->validated()['current_password'],
            $user->password
        )) {
            return response()->json([
                'message' => 'The current password is incorrect.',
                'errors' => [
                    'current_password' => [
                        'The current password is incorrect.',
                    ],
                ],
            ], 422);
        }

        $user->forceFill([
            'password' => Hash::make(
                $request->validated()['new_password']
            ),
            'remember_token' => Str::random(60),
        ])->save();

        // Keep the current token, revoke other Sanctum tokens.
        $currentToken = $user->currentAccessToken();

        if ($currentToken && $currentToken->getKey() !== null) {
            $user->tokens()
                ->where('id', '!=', $currentToken->getKey())
                ->delete();
        }

        return response()->json([
            'message' => 'Password changed successfully.',
        ]);
    }


}
