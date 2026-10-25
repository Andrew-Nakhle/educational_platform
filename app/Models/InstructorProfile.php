<?php

namespace App\Models;

use App\Enums\Instructor\TeachingMajor;
use Illuminate\Database\Eloquent\Model;

class InstructorProfile extends Model
{

    protected $fillable =['first_name', 'last_name','father_name', 'avatar', 'teaching_major', 'bio', 'reject_reason'];
    protected $casts=['teaching_major' => TeachingMajor::class];

    public function user()
    {
        return $this->belongsTo(User::class,'user_id');
    }
    public function certificates()
    {
        return $this->hasMany(InstructorCertificate::class);
    }
    public function courses()
    {
        return $this->hasMany(Course::class);
    }
    public function reviews()
    {
        return $this->hasMany(InstructorReview::class);
    }
}
