<?php

namespace App\Models;

use App\Enums\Course\CourseStatus;
use App\Enums\Course\DifficultyLevel;
use App\Enums\Course\Language;
use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $fillable = [
        'instructor_profile_id',
        'category_id',
        'title',
        'description',
        'price',
        'thumbnail',
        'language',
        'difficulty_level',
        'what_you_will_learn',
        'duration_hours',
        'status',
    ];
    protected $casts=[
        'difficulty_level'=>DifficultyLevel::class,
        'status' => CourseStatus::class,
        'duration_seconds' => 'integer',
        'language' => Language::class,

    ];
    public function instructorProfile()
    {
        return $this->belongsTo(InstructorProfile::class);
    }

    public function category(){
        return $this->belongsTo(Category::class);
    }
    public function sections()
    {
        return $this->hasMany(Section::class);
    }
    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
