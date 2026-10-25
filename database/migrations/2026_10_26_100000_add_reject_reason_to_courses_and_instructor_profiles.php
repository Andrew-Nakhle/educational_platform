<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('reject_reason', 500)->nullable();
        });

        Schema::table('instructor_profiles', function (Blueprint $table) {
            $table->string('reject_reason', 500)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('reject_reason');
        });

        Schema::table('instructor_profiles', function (Blueprint $table) {
            $table->dropColumn('reject_reason');
        });
    }
};
