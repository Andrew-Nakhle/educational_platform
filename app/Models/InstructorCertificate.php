<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstructorCertificate extends Model
{
    protected $fillable = [
        'certification',
    ];
    public function instructorProfile()
    {
        return $this->belongsTo(InstructorProfile::class);
    }
}
