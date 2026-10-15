<?php

namespace App\Models;

use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'user_id',
        'course_id',
        'payment_method',
        'provider',
        'amount',
        'currency',
        'status',
        'provider_payment_id',
        'paid_at',
        'stripe_checkout_session_id'
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => PaymentStatus::class,
            'provider' => PaymentProvider::class,
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
