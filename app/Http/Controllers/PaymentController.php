<?php

namespace App\Http\Controllers;

use App\Enums\Payment\PaymentStatus;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Throwable;
use UnexpectedValueException;

class PaymentController extends Controller
{
    public function webhook(Request $request)
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
                config('services.stripe.webhook_secret')
            );
        } catch (UnexpectedValueException | SignatureVerificationException $e) {
            return response()->json([
                'message' => 'Invalid Stripe webhook.',
            ], 400);
        }

        $supportedEvents = [
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
            'checkout.session.async_payment_failed',
            'checkout.session.expired',
            'payment_intent.succeeded',
            'payment_intent.payment_failed',
            'payment_intent.canceled',
        ];

        if (! in_array($event->type, $supportedEvents, true)) {
            return response()->json(['received' => true]);
        }

        $object = $event->data->object;
        $isCheckout = str_starts_with(
            $event->type,
            'checkout.session.'
        );

        $paymentId = $object->metadata->payment_id ?? null;

        if (! $paymentId) {
            Log::warning('Stripe event missing payment_id metadata.', [
                'event_id' => $event->id,
                'event_type' => $event->type,
            ]);

            return response()->json(['received' => true]);
        }

        try {
            DB::transaction(function () use (
                $paymentId,
                $object,
                $event,
                $isCheckout
            ) {
                $payment = Payment::query()
                    ->lockForUpdate()
                    ->find($paymentId);

                if (! $payment) {
                    Log::warning('Stripe payment record not found.', [
                        'payment_id' => $paymentId,
                        'event_id' => $event->id,
                    ]);

                    return;
                }

                if (
                    $payment->provider->value !== 'stripe'
                    || ! $payment->user_id
                    || ! $payment->course_id
                ) {
                    Log::error('Invalid payment record for Stripe event.', [
                        'payment_id' => $payment->id,
                    ]);

                    return;
                }

                if ($payment->status === PaymentStatus::PAID) {
                    return;
                }

                if (! in_array($payment->status, [
                    PaymentStatus::PENDING,
                    PaymentStatus::PROCESSING,
                ], true)) {
                    return;
                }

                if ($isCheckout) {
                    $sessionId = $object->id ?? null;

                    if (
                        ! $sessionId
                        || $payment->stripe_checkout_session_id !== $sessionId
                    ) {
                        Log::warning('Checkout session does not match payment.', [
                            'payment_id' => $payment->id,
                        ]);

                        return;
                    }

                    $amount = $object->amount_total ?? null;
                    $currency = strtolower($object->currency ?? '');
                    $paymentIntentId = $object->payment_intent ?? null;

                    if ($event->type === 'checkout.session.expired') {
                        $payment->update([
                            'status' => PaymentStatus::EXPIRED,
                        ]);

                        return;
                    }

                    if ($event->type === 'checkout.session.async_payment_failed') {
                        $payment->update([
                            'status' => PaymentStatus::FAILED,
                        ]);

                        return;
                    }

                    if (($object->payment_status ?? null) !== 'paid') {
                        return;
                    }
                }  else {
                $intentId = $object->id ?? null;

                if (! $intentId) {
                    Log::warning('Stripe event missing PaymentIntent ID.', [
                        'payment_id' => $payment->id,
                        'event_type' => $event->type,
                    ]);

                    return;
                }

                /*
                 * Web Checkout:
                 * A failed card attempt does not mean the Checkout Session
                 * has permanently failed. Let Checkout events determine
                 * whether the session is paid or expired.
                 */
                $isCheckoutIntent =
                    $payment->provider_payment_id === null
                    && $payment->stripe_checkout_session_id !== null
                    && (string) ($object->metadata->payment_id ?? '')
                    === (string) $payment->id;

                if ($isCheckoutIntent) {
                    if ($event->type === 'payment_intent.payment_failed') {
                        Log::info(
                            'Checkout payment attempt failed; retry remains available.',
                            [
                                'payment_id' => $payment->id,
                                'payment_intent_id' => $intentId,
                                'checkout_session_id' =>
                                    $payment->stripe_checkout_session_id,
                                'stripe_status' => $object->status ?? null,
                            ]
                        );
                    }

                    // Checkout Session events handle web payment completion
                    // and expiration. Do not treat one declined card as final.
                    return;
                }

                /*
                 * Mobile PaymentIntent:
                 * Its ID must match the PaymentIntent stored in our database.
                 */
                if ($payment->provider_payment_id !== $intentId) {
                    Log::warning('PaymentIntent does not match payment.', [
                        'payment_id' => $payment->id,
                        'database_intent_id' => $payment->provider_payment_id,
                        'stripe_intent_id' => $intentId,
                        'event_type' => $event->type,
                    ]);

                    return;
                }

                if ($event->type === 'payment_intent.payment_failed') {
                    if (($object->status ?? null) === 'requires_payment_method') {
                        $payment->update([
                            'status' => PaymentStatus::PENDING,
                        ]);
                    }

                    Log::info('Mobile payment attempt failed.', [
                        'payment_id' => $payment->id,
                        'payment_intent_id' => $intentId,
                        'stripe_status' => $object->status ?? null,
                    ]);

                    return;
                }

                if ($event->type === 'payment_intent.canceled') {
                    $wasAbandonedByTimeout =
                        ($object->cancellation_reason ?? null) === 'abandoned';

                    $payment->update([
                        'status' => $wasAbandonedByTimeout
                            ? PaymentStatus::EXPIRED
                            : PaymentStatus::CANCELLED,
                    ]);

                    return;
                }

                if (($object->status ?? null) !== 'succeeded') {
                    return;
                }

                $amount = $object->amount_received ?? $object->amount ?? null;
                $currency = strtolower($object->currency ?? '');
                $paymentIntentId = $intentId;
            }

                $expectedAmount = (int) round(
                    (float) $payment->amount * 100
                );

                $expectedCurrency = strtolower($payment->currency);

                if (
                    $amount === null
                    || (int) $amount !== $expectedAmount
                    || $currency !== $expectedCurrency
                    || ! $paymentIntentId
                ) {
                    Log::error('Stripe amount or currency mismatch.', [
                        'payment_id' => $payment->id,
                    ]);

                    return;
                }

                $payment->update([
                    'status' => PaymentStatus::PAID,
                    'paid_at' => now(),
                    'provider_payment_id' => $paymentIntentId,
                ]);

                $payment->user->enrollments()->firstOrCreate(
                    ['course_id' => $payment->course_id],
                    ['enrolled_at' => now()]
                );
            });
        } catch (Throwable $e) {
            Log::error('Stripe webhook processing failed.', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Webhook processing failed.',
            ], 500);
        }

        return response()->json(['received' => true]);
    }
}
