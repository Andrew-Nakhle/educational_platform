<?php

namespace App\Contracts\Payment;

interface PaymentProviderInterface
{
    public function createPayment(float $amount, string $currency, int $paymentId): array;

    public function verifyPayment(string $providerPaymentId): bool;

    public function refundPayment(string $providerPaymentId): bool;
}
