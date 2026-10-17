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
                } else {
                    $intentId = $object->id ?? null;

                    if (
                        ! $intentId
                        || $payment->provider_payment_id !== $intentId
                    ) {
                        Log::warning('PaymentIntent does not match payment.', [
                            'payment_id' => $payment->id,
                        ]);

                        return;
                    }

                    if ($event->type === 'payment_intent.payment_failed') {
                        $payment->update([
                            'status' => PaymentStatus::FAILED,
                        ]);

                        return;
                    }

                    if ($event->type === 'payment_intent.canceled') {
                        $payment->update([
                            'status' => PaymentStatus::CANCELLED,
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
