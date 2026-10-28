<?php

namespace Tests\Support;

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Replaces Stripe's HTTP transport in tests: no network calls are made,
 * every request is recorded and answered with a canned test-mode object.
 */
class FakeStripeHttpClient implements ClientInterface
{
    /** @var array<int, array{method: string, url: string, params: array}> */
    public array $requests = [];

    /**
     * When set, every request is answered with this Stripe error instead:
     * [http status, error type, message]. 401 becomes an
     * AuthenticationException in the Stripe SDK; 500 an API error.
     *
     * @var array{0: int, 1: string, 2: string}|null
     */
    public ?array $failWith = null;

    /** The `status` of the Checkout Sessions it returns (open, expired, complete). */
    public string $sessionStatus = 'open';

    public function failWithAuthenticationError(): self
    {
        // Stripe echoes a masked key in this message; it must never be logged as is.
        $this->failWith = [401, 'invalid_request_error', 'Invalid API Key provided: sk_test_****fake'];

        return $this;
    }

    public function failWithServerError(): self
    {
        $this->failWith = [500, 'api_error', 'Something went wrong on the fake Stripe.'];

        return $this;
    }

    public static function install(): self
    {
        $client = new self;

        ApiRequestor::setHttpClient($client);

        return $client;
    }

    public static function uninstall(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = [
            'method' => strtolower($method),
            'url' => $absUrl,
            'params' => $params,
        ];

        if ($this->failWith !== null) {
            [$status, $type, $message] = $this->failWith;

            return [json_encode(['error' => ['type' => $type, 'message' => $message]]), $status, new \Stripe\Util\CaseInsensitiveArray([])];
        }

        $path = parse_url($absUrl, PHP_URL_PATH);

        $body = match (true) {
            str_starts_with($path, '/v1/checkout/sessions') => [
                'id' => 'cs_test_example',
                'object' => 'checkout.session',
                'url' => 'https://checkout.stripe.test/c/pay/cs_test_example',
                'status' => $this->sessionStatus,
                'payment_status' => 'unpaid',
                'success_url' => $params['success_url'] ?? null,
                'cancel_url' => $params['cancel_url'] ?? null,
            ],
            str_starts_with($path, '/v1/payment_intents') => [
                'id' => 'pi_test_example',
                'object' => 'payment_intent',
                'client_secret' => 'pi_test_example_secret_placeholder',
                'status' => 'requires_payment_method',
            ],
            default => ['object' => 'unknown'],
        };

        return [json_encode($body), 200, new \Stripe\Util\CaseInsensitiveArray([])];
    }

    public function lastParams(): array
    {
        return end($this->requests)['params'] ?? [];
    }
}
