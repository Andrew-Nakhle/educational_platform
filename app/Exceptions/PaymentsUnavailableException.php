<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Payments can't be taken right now: STRIPE_SECRET is empty, or Stripe
 * refused the key (authentication error). The API answers 503 with a
 * neutral message; the real cause is only logged (without secrets).
 */
class PaymentsUnavailableException extends RuntimeException
{
    public const PUBLIC_MESSAGE = 'Payments are temporarily unavailable. Please try again later.';
}
