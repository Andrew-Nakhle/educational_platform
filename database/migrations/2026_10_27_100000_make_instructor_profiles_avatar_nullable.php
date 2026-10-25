<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The instructor sign-up accepts no avatar (the request rule is
     * nullable), so the column must accept NULL. Laravel's native
     * change() (no doctrine/dbal) works on SQLite, MySQL and PostgreSQL.
     */
    public function up(): void
    {
        Schema::table('instructor_profiles', function (Blueprint $table) {
            $table->string('avatar')->nullable()->change();
        });
    }

    /**
     * Back to NOT NULL. Rows without an avatar get an empty string first,
     * otherwise the change fails on them.
     */
    public function down(): void
    {
        DB::table('instructor_profiles')->whereNull('avatar')->update(['avatar' => '']);

        Schema::table('instructor_profiles', function (Blueprint $table) {
            $table->string('avatar')->nullable(false)->change();
        });
    }
};
