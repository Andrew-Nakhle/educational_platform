<?php

namespace App\Enums\Payment;

enum PaymentProvider: string
{
    case STRIPE = 'stripe';
    case SHAM_CASH = 'sham_cash';
}
