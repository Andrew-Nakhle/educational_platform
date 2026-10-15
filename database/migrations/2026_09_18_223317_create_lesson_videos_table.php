<?php

use App\Enums\Course\VideoStatus;
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
        Schema::create('lesson_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->unique()
                ->constrained('lessons')
                ->cascadeOnDelete();


            $table->string('source_path');
            $table->string('playlist_path')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->string('status')->default(videoStatus::PROCESSING->value);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lesson_videos');
    }
};
