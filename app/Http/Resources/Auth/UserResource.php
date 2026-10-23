<?php

namespace App\Http\Resources\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'phone' => $this->phone,
            'email' => $this->email,
            'gender' => $this->gender,
            'age' => $this->birthday?->age,
            'role' => $this->getRoleNames()->first(),
            'status' => $this->status->value,
            'profile' => $this->getProfileData(),
        ];
    }

    private function getProfileData(): ?array
    {
        // Student Profile
        if ($this->hasRole('student') && $this->studentProfile) {
            return [
                'type' => 'student',
                'id' => $this->studentProfile->id,
                'first_name' => $this->studentProfile->first_name,
                'father_name' => $this->studentProfile->father_name,
                'last_name' => $this->studentProfile->last_name,
                'avatar' => $this->studentProfile->avatar
                    ? Storage::disk(config('filesystems.upload_disk'))->temporaryUrl(
                        $this->studentProfile->avatar,
                        now()->addMinutes(30)
                    )
                    : null,
            ];
        }

        // Instructor Profile
        if ($this->hasRole('instructor') && $this->instructorProfile) {
            return [
                'type' => 'instructor',
                'id' => $this->instructorProfile->id,
                'first_name' => $this->instructorProfile->first_name,
                'father_name' => $this->instructorProfile->father_name,
                'last_name' => $this->instructorProfile->last_name,
//استعملت انا هون temporaryUrl لان عندي ال bucket تبع backbaze هو private وعطيتو مدة صلاحية 30 دقيقة
                'avatar' => $this->instructorProfile->avatar
                    ? Storage::disk(config('filesystems.upload_disk'))->temporaryUrl(
                        $this->instructorProfile->avatar,
                        now()->addMinutes(30)
                    )
                    : null,

                'teaching_major' => $this->instructorProfile->teaching_major?->value,

                'bio' => $this->instructorProfile->bio,

                'certifications' => $this->instructorProfile->certificates
                    ->map(function ($certificate) {
                        return [
                            'id' => $certificate->id,

                            'certification' => $certificate->certification
                                ? Storage::disk(config('filesystems.upload_disk'))->temporaryUrl(
                                    $certificate->certification,
                                    now()->addMinutes(30)
                                )
                                : null,
                        ];
                    })
                    ->values(),
            ];
        }

        // Admin
        if ($this->hasRole('admin')) {
            return [
                'type' => 'admin',
            ];
        }

        return null;
    }
}
