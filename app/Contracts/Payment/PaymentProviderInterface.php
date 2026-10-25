<?php

namespace App\Contracts\Payment;

interface PaymentProviderInterface
{
    public function createPayment(float $amount, string $currency, int $paymentId,int $courseId): array;

    public function verifyPayment(string $providerPaymentId): bool;

    public function refundPayment(string $providerPaymentId): bool;
}
