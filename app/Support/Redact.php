<?php

namespace App\Support;

/**
 * Removes secrets from text before it is logged: Stripe keys and webhook
 * secrets (Stripe echoes part of the key in some errors), Stripe client
 * secrets, bearer tokens and the signature of signed or presigned URLs.
 */
class Redact
{
    private const PATTERNS = [
        // sk_live_…, rk_test_…, pk_…, whsec_…, and pi_…_secret_… client secrets
        '/\b(sk|rk|pk|whsec)_[A-Za-z0-9_*]+/' => '[redacted]',
        '/\b(pi|seti)_[A-Za-z0-9]+_secret_[A-Za-z0-9]+/' => '[redacted]',
        // Authorization: Bearer <token>
        '/\bBearer\s+[A-Za-z0-9|._~+\/=-]+/i' => 'Bearer [redacted]',
        // ?X-Amz-Signature=…, ?signature=…, X-Amz-Credential=…
        '/\b(X-Amz-Signature|X-Amz-Credential|X-Amz-Security-Token|signature)=[^&\s"\']+/i' => '$1=[redacted]',
    ];

    public static function secrets(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        return preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text) ?? '';
    }
}
