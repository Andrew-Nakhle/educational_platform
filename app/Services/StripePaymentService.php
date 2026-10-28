<?php

namespace App\Services;

use App\Contracts\Payment\PaymentProviderInterface;
use App\Exceptions\PaymentsUnavailableException;
use Stripe\StripeClient;

class StripePaymentService implements PaymentProviderInterface
{
    /**
     * Created on first use, so routes that never make a payment
     * work without STRIPE_SECRET.
     */
    protected ?StripeClient $stripe = null;

    protected function stripe(): StripeClient
    {
        if ($this->stripe) {
            return $this->stripe;
        }

        $secret = config('services.stripe.secret');

        if (! is_string($secret) || trim($secret) === '') {
            throw new PaymentsUnavailableException(
                'Stripe secret is not configured.'
            );
        }

        return $this->stripe = new StripeClient($secret);
    }

    public function createPayment(
        float $amount,
        string $currency,
        int $paymentId,
        int $courseId
    ): array {
        $frontendUrl = rtrim(
            config('app.frontend_url', 'http://localhost:5173'),
            '/'
        );

        $session = $this->stripe()->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($currency),
                    'product_data' => [
                        'name' => 'Course Payment',
                    ],
                    'unit_amount' => (int) round($amount * 100),
                ],
                'quantity' => 1,
            ]],
            'metadata' => [
                'payment_id' => (string) $paymentId,
                'course_id' => (string) $courseId,
            ],
            'payment_intent_data' => [
                'metadata' => [
                    'payment_id' => (string) $paymentId,
                    'course_id' => (string) $courseId,
                ],
            ],
            'success_url' => $frontendUrl
                . '/payment/success?course_id=' . $courseId
                . '&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $frontendUrl
                . '/payment/cancel?course_id=' . $courseId,
        ]);

        return [
            'checkout_session_id' => $session->id,
            'checkout_url' => $session->url,
        ];
    }

    public function createPaymentIntent(
        float $amount,
        string $currency,
        int $paymentId
    ): array {
        $intent = $this->stripe()->paymentIntents->create([
            'amount' => (int) round($amount * 100),
            'currency' => strtolower($currency),
            'metadata' => [
                'payment_id' => (string) $paymentId,
            ],
            'automatic_payment_methods' => [
                'enabled' => true,
            ],
        ]);

        return [
            'provider_payment_id' => $intent->id,
            'client_secret' => $intent->client_secret,
        ];
    }

    public function retrieveCheckoutSession(string $sessionId): array
    {
        $session = $this->stripe()->checkout->sessions->retrieve($sessionId);

        return [
            'checkout_session_id' => $session->id,
            'checkout_url' => $session->url,
            'status' => $session->status,
            'payment_status' => $session->payment_status,
        ];
    }

    public function retrievePaymentIntent(string $intentId): array
    {
        $intent = $this->stripe()->paymentIntents->retrieve($intentId);

        return [
            'provider_payment_id' => $intent->id,
            'client_secret' => $intent->client_secret,
            'status' => $intent->status,
        ];
    }

    public function expireCheckoutSessionIfOpen(
        string $sessionId
    ): array {
        $session = $this->stripe()->checkout->sessions->retrieve(
            $sessionId
        );

        if ($session->status === 'open') {
            $session = $this->stripe()->checkout->sessions->expire(
                $sessionId
            );
        }

        return [
            'checkout_session_id' => $session->id,
            'status' => $session->status,
            'payment_status' => $session->payment_status,
        ];
    }

    public function cancelPaymentIntentIfPossible(
        string $intentId
    ): array {
        $intent = $this->stripe()->paymentIntents->retrieve(
            $intentId
        );

        $cancelableStatuses = [
            'requires_payment_method',
            'requires_confirmation',
            'requires_action',
            'requires_capture',
        ];

        if (in_array($intent->status, $cancelableStatuses, true)) {
            $intent = $this->stripe()->paymentIntents->cancel(
                $intentId,
                [
                    'cancellation_reason' => 'abandoned',
                ]
            );
        }

        return [
            'provider_payment_id' => $intent->id,
            'status' => $intent->status,
            'cancellation_reason' => $intent->cancellation_reason,
        ];
    }

    public function verifyPayment(string $providerPaymentId): bool
    {
        $intent = $this->stripe()->paymentIntents->retrieve(
            $providerPaymentId
        );

        return $intent->status === 'succeeded';
    }

    public function refundPayment(string $providerPaymentId): bool
    {
        $this->stripe()->refunds->create([
            'payment_intent' => $providerPaymentId,
        ]);

        return true;
    }
}
