<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lesson extends Model
{
    protected $fillable = [
        'section_id',
        'title',
        'description',
        'duration_seconds',
        'sort_order',
    ];

    protected $casts = [
        'duration_seconds' => 'integer',
        'sort_order' => 'integer',
    ];
    public function section()
    {
        return $this->belongsTo(Section::class);
    }
    public function video()
    {
        return $this->hasOne(LessonVideo::class);
    }
}
