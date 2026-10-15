<?php

namespace Database\Seeders;

use App\Enums\User\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class StudentSeeder extends Seeder
{
    public function run(): void
    {
        $students = [
            [
                'email' => 'student1@gmail.com',
                'phone' => '0991111111',
                'gender' => 'male',
                'birthday' => '2000-05-10',
                'first_name' => 'Andrew',
                'father_name' => 'George',
                'last_name' => 'Nakhle',
                'status' => UserStatus::ACTIVE,
            ],
            [
                'email' => 'student2@gmail.com',
                'phone' => '0992222222',
                'gender' => 'female',
                'birthday' => '2002-08-15',
                'first_name' => 'Sara',
                'father_name' => 'Ali',
                'last_name' => 'Hassan',
                'status' => UserStatus::INACTIVE,
            ],
            [
                'email' => 'student3@gmail.com',
                'phone' => '0993333333',
                'gender' => 'male',
                'birthday' => '1999-12-20',
                'first_name' => 'Omar',
                'father_name' => 'Ahmad',
                'last_name' => 'Khaled',
                'status' => UserStatus::BLOCKED,
            ],
        ];

        foreach ($students as $student) {
            $user = User::create([
                'email' => $student['email'],
                'phone' => $student['phone'],
                'gender' => $student['gender'],
                'birthday' => $student['birthday'],
                'password' => Hash::make('password123'),
                'status' => $student['status'],
            ]);

            $user->assignRole('student');

            $user->studentProfile()->create([
                'first_name' => $student['first_name'],
                'father_name' => $student['father_name'],
                'last_name' => $student['last_name'],
            ]);
        }
    }
}
