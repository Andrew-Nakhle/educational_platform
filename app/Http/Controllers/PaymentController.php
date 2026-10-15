<?php

namespace App\Http\Controllers;

use App\Enums\Course\CourseStatus;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentStatus;
use App\Models\Course;
use App\Models\Payment;
use App\Services\StripePaymentService;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use Throwable;

class PaymentController extends Controller

{
    public function __construct(
        private StripePaymentService $stripePaymentService
    ) {}

//    public function createPayment(int $courseId)
//    {
//        $student = auth()->user();
//
//        $course = Course::find($courseId);
//
//        if (! $course) {
//            return response()->json([
//                'message' => 'Course not found.',
//            ], 404);
//        }
//
//        if ($course->status !== CourseStatus::PUBLISHED) {
//            return response()->json([
//                'message' => 'This course is not available for purchase.',
//            ], 422);
//        }
//
//        if ($course->price <= 0) {
//            return response()->json([
//                'message' => 'This course is free.',
//            ], 422);
//        }
//
//        $alreadyEnrolled = $student->enrollments()
//            ->where('course_id', $course->id)
//            ->exists();
//
//        if ($alreadyEnrolled) {
//            return response()->json([
//                'message' => 'You are already enrolled in this course.',
//            ], 422);
//        }
//
//        $existingPayment = $student->payments()
//            ->where('course_id', $course->id)
//            ->whereIn('status', [
//                PaymentStatus::PENDING,
//                PaymentStatus::PROCESSING,
//            ])
//            ->latest()
//            ->first();
//
//        if ($existingPayment) {
//            return response()->json([
//                'message' => 'You already have a pending payment for this course.',
//                'payment' => $existingPayment,
//            ], 422);
//        }
//
//        $payment = $student->payments()->create([
//            'course_id' => $course->id,
//            'payment_method' => 'card',
//            'provider' => PaymentProvider::STRIPE,
//            'amount' => $course->price,
//            'currency' => 'USD',
//            'status' => PaymentStatus::PENDING,
//        ]);
//
//        $stripePayment = $this->stripePaymentService->createPayment(
//            (float) $payment->amount,
//            $payment->currency,
//            $payment->id
//        );
//
//        $payment->update([
//            'provider_payment_id' => $stripePayment['provider_payment_id'],
//            'stripe_checkout_session_id' => $stripePayment['checkout_session_id'],
//            'status' => PaymentStatus::PROCESSING,
//        ]);
//
//        return response()->json([
//            'message' => 'Payment session created successfully.',
//            'payment_id' => $payment->id,
//            'checkout_url' => $stripePayment['checkout_url'],
//        ], 201);
//    }


    public function webhook(Request $request)
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature'),
                config('services.stripe.webhook_secret')
            );
        } catch (UnexpectedValueException $e) {
            return response()->json([
                'message' => 'Invalid payload.',
            ], 400);
        } catch (SignatureVerificationException $e) {
            return response()->json([
                'message' => 'Invalid signature.',
            ], 400);
        }

        $session = $event->data->object;
        Log::info('Stripe webhook received.', [
            'event_type' => $event->type,
            'session_id' => $session->id ?? null,
            'metadata' => $session->metadata->toArray()
                ?? [],
            'payment_intent' => $session->payment_intent ?? null,
        ]);
        if (! in_array($event->type, [
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
            'checkout.session.async_payment_failed',
            'checkout.session.expired',
        ], true)) {
            return response()->json([
                'received' => true,
            ]);
        }

        $paymentId = $session->metadata->payment_id ?? null;

        if (! $paymentId) {
            Log::warning('Stripe Checkout session has no payment_id metadata.', [
                'session_id' => $session->id ?? null,
                'event_type' => $event->type,
            ]);

            return response()->json([
                'received' => true,
            ]);
        }

        try {
            DB::transaction(function () use ($paymentId, $session, $event) {
                $payment = Payment::query()
                    ->lockForUpdate()
                    ->find($paymentId);

                if (! $payment) {
                    Log::warning('Payment not found for Stripe event.', [
                        'payment_id' => $paymentId,
                    ]);

                    return;
                }

                // Never change a successful payment to another status.
                if ($payment->status === PaymentStatus::PAID) {
                    return;
                }

                if (! in_array($payment->status, [
                    PaymentStatus::PENDING,
                    PaymentStatus::PROCESSING,
                ], true)) {
                    return;
                }

                // Handle a failed asynchronous payment.
                if ($event->type === 'checkout.session.async_payment_failed') {
                    $payment->update([
                        'status' => PaymentStatus::FAILED,
                    ]);

                    return;
                }

                // Handle an expired Checkout Session.
                if ($event->type === 'checkout.session.expired') {
                    $payment->update([
                        'status' => PaymentStatus::EXPIRED,
                    ]);

                    return;
                }

                // Do not enroll until Stripe confirms payment.
                if (($session->payment_status ?? null) !== 'paid') {
                    return;
                }

                $expectedAmount = (int) round(
                    (float) $payment->amount * 100
                );

                $receivedAmount = $session->amount_total ?? null;

                $expectedCurrency = strtolower($payment->currency);
                $receivedCurrency = strtolower($session->currency ?? '');

                if (
                    $receivedAmount === null
                    || $receivedAmount !== $expectedAmount
                    || $receivedCurrency !== $expectedCurrency
                ) {
                    Log::error('Stripe payment amount or currency mismatch.', [
                        'payment_id' => $payment->id,
                        'expected_amount' => $expectedAmount,
                        'received_amount' => $receivedAmount,
                        'expected_currency' => $expectedCurrency,
                        'received_currency' => $receivedCurrency,
                    ]);

                    return;
                }

                $payment->loadMissing(['user', 'course']);

                if (! $payment->user || ! $payment->course) {
                    Log::error('Payment is missing its user or course.', [
                        'payment_id' => $payment->id,
                    ]);

                    return;
                }

                $paymentIntentId = $session->payment_intent ?? null;

                if (! $paymentIntentId) {
                    Log::error('Stripe PaymentIntent ID is missing.', [
                        'payment_id' => $payment->id,
                        'session_id' => $session->id ?? null,
                    ]);

                    return;
                }

                $payment->update([
                    'status' => PaymentStatus::PAID,
                    'paid_at' => now(),
                    'provider_payment_id' => $paymentIntentId,
                ]);

                $payment->user->enrollments()->firstOrCreate(
                    [
                        'course_id' => $payment->course_id,
                    ],
                    [
                        'enrolled_at' => now(),
                    ]
                );
            });
        } catch (Throwable $e) {
            Log::error('Failed to process Stripe webhook.', [
                'payment_id' => $paymentId,
                'event_type' => $event->type,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Webhook processing failed.',
            ], 500);
        }

        return response()->json([
            'received' => true,
        ]);
    }
}
