<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstructorReview extends Model
{
    protected $fillable = [
        'user_id',
        'instructor_profile_id',
        'rating',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function instructorProfile(): BelongsTo
    {
        return $this->belongsTo(InstructorProfile::class);
    }
}
