<?php

namespace App\Models;

use App\Enums\Course\VideoResolution;
use App\Enums\Course\VideoStatus;
use Illuminate\Database\Eloquent\Model;


class LessonVideo extends Model
{
    protected $fillable = [
        'lesson_id',
        'source_path',
        'playlist_path',
        'duration_seconds',
        'status',
    ];

    protected $casts = [
        'duration_seconds' => 'integer',
        'status' => VideoStatus::class,
    ];

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }
}
