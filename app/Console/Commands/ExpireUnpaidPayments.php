<?php

namespace App\Console\Commands;

use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentStatus;

use App\Models\Payment;
use App\Services\StripePaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExpireUnpaidPayments extends Command
{
    protected $signature = 'payments:expire-unpaid';

    protected $description =
        'Expire unpaid Stripe payments after 10 minutes';

    public function handle(
        StripePaymentService $stripePaymentService
    ): int {
        $cutoff = now()->subMinutes(10);

        Payment::query()
            ->where('provider', PaymentProvider::STRIPE->value)
            ->whereIn('status', [
                PaymentStatus::PENDING->value,
                PaymentStatus::PROCESSING->value,
            ])
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($payments) use (
                $stripePaymentService
            ) {
                foreach ($payments as $payment) {
                    $this->expirePayment(
                        $payment,
                        $stripePaymentService
                    );
                }
            });

        return self::SUCCESS;
    }

    private function expirePayment(
        Payment $payment,
        StripePaymentService $stripe
    ): void {
        try {
            /*
             * Web Checkout:
             * Expire the actual Stripe session before
             * changing our local payment status.
             */
            if ($payment->stripe_checkout_session_id) {
                $session = $stripe->expireCheckoutSessionIfOpen(
                    $payment->stripe_checkout_session_id
                );

                if ($session['status'] === 'expired') {
                    $this->updatePaymentStatus(
                        $payment,
                        PaymentStatus::EXPIRED
                    );

                    return;
                }

                if (
                    $session['status'] === 'complete'
                    && $session['payment_status'] === 'paid'
                ) {
                    Log::info(
                        'Checkout is paid; waiting for webhook processing.',
                        [
                            'payment_id' => $payment->id,
                            'checkout_session_id' =>
                                $payment->stripe_checkout_session_id,
                        ]
                    );

                    return;
                }

                Log::info(
                    'Checkout session was not expired by timeout.',
                    [
                        'payment_id' => $payment->id,
                        'checkout_status' => $session['status'],
                        'payment_status' => $session['payment_status'],
                    ]
                );

                return;
            }

            /*
             * Mobile PaymentIntent:
             * Cancel only if Stripe says it is cancellable.
             */
            if ($payment->provider_payment_id) {
                $intent = $stripe->cancelPaymentIntentIfPossible(
                    $payment->provider_payment_id
                );

                if ($intent['status'] === 'succeeded') {
                    Log::info(
                        'PaymentIntent succeeded; waiting for webhook processing.',
                        [
                            'payment_id' => $payment->id,
                            'payment_intent_id' =>
                                $payment->provider_payment_id,
                        ]
                    );

                    return;
                }

                if ($intent['status'] === 'canceled') {
                    $finalStatus =
                        $intent['cancellation_reason'] === 'abandoned'
                            ? PaymentStatus::EXPIRED
                            : PaymentStatus::CANCELLED;

                    $this->updatePaymentStatus(
                        $payment,
                        $finalStatus
                    );

                    return;
                }

                // Do not expire a payment that Stripe is still processing.
                Log::info(
                    'PaymentIntent was not expired because it is not cancellable.',
                    [
                        'payment_id' => $payment->id,
                        'payment_intent_id' =>
                            $payment->provider_payment_id,
                        'stripe_status' => $intent['status'],
                    ]
                );

                return;
            }

            /*
             * A stale record with no Stripe session or PaymentIntent.
             */
            $this->updatePaymentStatus(
                $payment,
                PaymentStatus::EXPIRED
            );
        } catch (Throwable $e) {
            Log::error('Failed to expire unpaid Stripe payment.', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function updatePaymentStatus(
        Payment $payment,
        PaymentStatus $status
    ): void {
        $updated = Payment::query()
            ->whereKey($payment->id)
            ->whereIn('status', [
                PaymentStatus::PENDING->value,
                PaymentStatus::PROCESSING->value,
            ])
            ->update([
                'status' => $status->value,
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            Log::info('Unpaid Stripe payment status updated.', [
                'payment_id' => $payment->id,
                'new_status' => $status->value,
            ]);
        }
    }
}
