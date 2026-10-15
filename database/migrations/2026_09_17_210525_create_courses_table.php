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
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_profile_id')->constrained('instructor_profiles')->cascadeOnDelete();

            $table->foreignId('category_id')->constrained('categories')->onDelete('restrict');

            $table->string('title');
            $table->longText('description');
            $table->decimal('price', 10, 2)->default(0);
            $table->string('thumbnail');//image

            $table->string('language');
            $table->string('difficulty_level');

            $table->longText('what_you_will_learn');

            $table->unsignedInteger('duration_seconds')->default(0);

            $table->string('status')->default('draft');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
