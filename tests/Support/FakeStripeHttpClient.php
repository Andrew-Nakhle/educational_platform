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

        $path = parse_url($absUrl, PHP_URL_PATH);

        $body = match (true) {
            str_starts_with($path, '/v1/checkout/sessions') => [
                'id' => 'cs_test_example',
                'object' => 'checkout.session',
                'url' => 'https://checkout.stripe.test/c/pay/cs_test_example',
                'status' => 'open',
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
