<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin payment row. Only ids that are safe to show (Stripe object ids);
 * never client secrets or checkout URLs (they are not stored anyway).
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->user?->studentProfile;

        return [
            'id' => $this->id,

            'student' => $this->user ? [
                'id' => $this->user->id,
                'name' => $profile
                    ? trim("{$profile->first_name} {$profile->last_name}")
                    : null,
            ] : null,

            'course' => $this->course ? [
                'id' => $this->course->id,
                'title' => $this->course->title,
            ] : null,

            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status?->value,
            'provider' => $this->provider?->value,
            'payment_method' => $this->payment_method,
            'provider_payment_id' => $this->provider_payment_id,
            'stripe_checkout_session_id' => $this->stripe_checkout_session_id,
            'paid_at' => $this->paid_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
