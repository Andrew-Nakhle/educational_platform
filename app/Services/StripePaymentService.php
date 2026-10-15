<?php

namespace App\Services;

use App\Contracts\Payment\PaymentProviderInterface;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

class StripePaymentService implements PaymentProviderInterface
{
    protected StripeClient $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(
            config('services.stripe.secret')
        );
    }

    public function createPayment(
        float $amount,
        string $currency,
        int $paymentId
    ): array {
        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',

            'line_items' => [
                [
                    'price_data' => [
                        'currency' => strtolower($currency),

                        'product_data' => [
                            'name' => 'Course Payment',
                        ],

                        'unit_amount' => (int) round($amount * 100),
                    ],

                    'quantity' => 1,
                ],
            ],

            'payment_intent_data' => [
                'metadata' => [
                    'payment_id' => $paymentId,
                ],
            ],

            'metadata' => [
                'payment_id' => $paymentId,
            ],

            'success_url' => config('app.frontend_url')
                . '/payment/success?session_id={CHECKOUT_SESSION_ID}',

            'cancel_url' => config('app.frontend_url')
                . '/payment/cancel',
        ]);
        Log::info('Stripe Checkout created', [
            'payment_id' => $paymentId,
            'session_id' => $session->id,
            'metadata' => $session->metadata->toArray(),
        ]);

        return [
            'checkout_session_id' => $session->id,

//            'provider_payment_id' => $session->payment_intent,

            'checkout_url' => $session->url,
        ];
    }

    public function verifyPayment(
        string $providerPaymentId
    ): bool {
        $paymentIntent = $this->stripe->paymentIntents->retrieve(
            $providerPaymentId
        );

        return $paymentIntent->status === 'succeeded';
    }

    public function refundPayment(
        string $providerPaymentId
    ): bool {
        $this->stripe->refunds->create([
            'payment_intent' => $providerPaymentId,
        ]);

        return true;
    }
}
