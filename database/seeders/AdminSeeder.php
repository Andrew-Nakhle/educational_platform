<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::firstOrCreate(
            [
                'email' => 'admin@gmail.com',
            ],
            [
                'phone' => '0999999999',
                'gender' => 'male',
                'password' => Hash::make('password'),
                'birthday' => '1990-01-01',
            ]
        );

        $admin->assignRole('admin');
    }
}
