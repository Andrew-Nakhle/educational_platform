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
        Schema::create('instructor_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_profile_id')->constrained('instructor_profiles')->cascadeOnDelete();

            $table->string('certification');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('instructor_certificates');
    }
};
