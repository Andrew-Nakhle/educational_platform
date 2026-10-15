<?php

namespace Database\Seeders;

use App\Enums\Instructor\TeachingMajor;
use App\Enums\User\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class InstructorSeeder extends Seeder
{
    public function run(): void
    {
        $instructors = [
            [
                'email' => 'instructor1@gmail.com',
                'phone' => '0994444444',
                'gender' => 'male',
                'birthday' => '1995-03-12',
                'first_name' => 'John',
                'father_name' => 'Michael',
                'last_name' => 'Smith',
                'teaching_major' => TeachingMajor::COMPUTER_SCIENCE,
                'bio' => 'Computer Science Instructor',
                'status' => UserStatus::ACTIVE,
            ],
            [
                'email' => 'instructor2@gmail.com',
                'phone' => '0995555555',
                'gender' => 'female',
                'birthday' => '1992-07-25',
                'first_name' => 'Maya',
                'father_name' => 'Samir',
                'last_name' => 'Haddad',
                'teaching_major' => TeachingMajor::SOFTWARE_ENGINEERING,
                'bio' => 'Software Engineering Instructor',
                'status' => UserStatus::INACTIVE,
            ],
            [
                'email' => 'instructor3@gmail.com',
                'phone' => '0996666666',
                'gender' => 'male',
                'birthday' => '1990-11-05',
                'first_name' => 'Daniel',
                'father_name' => 'Robert',
                'last_name' => 'Brown',
                'teaching_major' => TeachingMajor::DATA_SCIENCE,
                'bio' => 'Data Science Instructor',
                'status' => UserStatus::BLOCKED,
            ],
        ];

        foreach ($instructors as $instructor) {
            $user = User::create([
                'email' => $instructor['email'],
                'phone' => $instructor['phone'],
                'gender' => $instructor['gender'],
                'birthday' => $instructor['birthday'],
                'password' => Hash::make('password'),
                'status' => $instructor['status'],
            ]);

            $user->assignRole('instructor');

            $user->instructorProfile()->create([
                'first_name' => $instructor['first_name'],
                'father_name' => $instructor['father_name'],
                'last_name' => $instructor['last_name'],
                'teaching_major' => $instructor['teaching_major'],
                'bio' => $instructor['bio'],
                'avatar' => 'avatar/0txBXkctmftZU6PcLePVRYK8D6uPRWfZAHwvo0Uj.jpg',
            ]);
        }
    }
}
