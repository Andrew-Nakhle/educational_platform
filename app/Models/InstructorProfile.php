<?php

namespace App\Models;

use App\Enums\Instructor\TeachingMajor;
use Illuminate\Database\Eloquent\Model;

class InstructorProfile extends Model
{
    /**
     * Stored when an admin rejects an application without a reason, so the
     * account stays recognisable as a rejected application (and can re-apply).
     */
    public const DEFAULT_REJECT_REASON = 'Rejected by admin';

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
