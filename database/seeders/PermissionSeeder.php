<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Users
            'view users',
            'create users',
            'update users',
            'delete users',

            // Courses
            'view courses',
            'create courses',
            'update courses',
            'delete courses',
            'publish courses',

            // Categories
            'view categories',
            'create categories',
            'update categories',
            'delete categories',

            // Sections
            'view sections',
            'create sections',
            'update sections',
            'delete sections',

            // Lessons
            'view lessons',
            'create lessons',
            'update lessons',
            'delete lessons',

            // Enrollments
            'view enrollments',
            'create enrollments',
            'update enrollments',
            'delete enrollments',

            // Orders
            'view orders',
            'create orders',
            'update orders',
            'delete orders',

            // Payments
            'view payments',
            'manage payments',

            // Reviews
            'view reviews',
            'create reviews',
            'update reviews',
            'delete reviews',

            // Certificates
            'view certificates',
            'create certificates',
            'download certificates',

            // Earnings
            'view earnings',

            // Profile
            'view profile',
            'update profile',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }
    }
}
