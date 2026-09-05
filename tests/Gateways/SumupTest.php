<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Tests\Gateways;

use LaravelPay\LaravelPay\DTO\MerchantAccount;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\Gateways\Sumup;
use LaravelPay\LaravelPay\Tests\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use SumUp\Exception\ApiException;
use SumUp\HttpClient\HttpClientInterface;
use SumUp\HttpClient\RequestOptions;
use SumUp\HttpClient\Response;
use Throwable;

class FakeSumUpHttpClient implements HttpClientInterface
{
    /** @var array<string, array> map of "METHOD URL_PATH" => response body array */
    public array $responses = [];

    /** @var array<string, array<int, array|Throwable>> ordered response queue consumed per call */
    public array $responseQueue = [];

    /** @var array<string, array> map of "METHOD URL_PATH" => the request body actually sent */
    public array $lastRequestBody = [];

    /** @var array<string, array> map of "METHOD URL_PATH" => the request headers actually sent */
    public array $lastRequestHeaders = [];

    /** @var array<string, int> map of "METHOD URL_PATH" => number of times it was called */
    public array $callCounts = [];

    public function send(string $method, string $url, array $body, array $headers = [], ?RequestOptions $options = null): Response
    {
        // Normalize the URL path (strip query string) to use as the cache key.
        $path = strtok($url, '?') ?: $url;
        $key = strtoupper($method).' '.$path;

        $this->lastRequestBody[$key] = $body;
        $this->lastRequestHeaders[$key] = $headers;
        $this->callCounts[$key] = ($this->callCounts[$key] ?? 0) + 1;

        if (! empty($this->responseQueue[$key])) {
            $next = array_shift($this->responseQueue[$key]);

            if ($next instanceof Throwable) {
                throw $next;
            }

            // Queue entries can be a plain body array (status 200) or a
            // configured response ['status' => int, 'body' => array].
            if (is_array($next) && isset($next['status']) && is_int($next['status'])) {
                return new Response($next['status'], $next['body'] ?? []);
            }

            return new Response(200, $next);
        }

        if (! isset($this->responses[$key])) {
            throw new RuntimeException("FakeSumUpHttpClient: no stub for {$key}");
        }

        $entry = $this->responses[$key];

        // Responses can be a plain body array (status 200) or a configured
        // response ['status' => int, 'body' => array].
        if (is_array($entry) && isset($entry['status']) && is_int($entry['status'])) {
            return new Response($entry['status'], $entry['body'] ?? []);
        }

        return new Response(200, $entry);
    }
}

class SumupTest extends TestCase
{
    private function gateway(?FakeSumUpHttpClient $client = null): Sumup
    {
        return new Sumup('test_api_key', new NullLogger, $client ?? new FakeSumUpHttpClient);
    }

    public function test_create_credentials_splits_api_key_and_merchant_code(): void
    {
        $result = $this->gateway()->createCredentials('sk_live_abc|MERCHANT1');

        $this->assertSame('MERCHANT1', $result['merchant_id']);
        $this->assertSame(['api_key' => 'sk_live_abc'], $result['credentials']);
    }

    public function test_create_credentials_without_merchant_code(): void
    {
        $result = $this->gateway()->createCredentials('sk_live_abc');

        $this->assertNull($result['merchant_id']);
        $this->assertSame(['api_key' => 'sk_live_abc'], $result['credentials']);
    }

    public function test_refresh_credentials_is_a_noop_for_static_api_key(): void
    {
        $this->assertSame([], $this->gateway()->refreshCredentials('anything'));
    }

    public function test_create_payment_returns_the_hosted_checkout_url(): void
    {
        $client = new FakeSumUpHttpClient;
        $client->responses['POST /v0.1/checkouts'] = [
            'status' => 201,
            'body' => [
                'id' => 'checkout_abc',
                'checkout_reference' => 'ref_123',
                'hosted_checkout_url' => 'https://pay.sumup.com/hosted/checkout_abc',
                'status' => 'PENDING',
            ],
        ];

        $gateway = $this->gateway($client);

        $request = new PaymentRequest(
            amount: 12.5,
            currency: 'eur',
            to: new MerchantAccount(
                credentials: ['api_key' => 'sk_live_abc'],
                merchantId: 'MERCHANT1',
                email: 'merchant@example.com',
            ),
            locale: 'it-IT',
            purchaseCountry: 'IT',
            reference: 'ref_123',
        );

        $result = $gateway->createPayment($request);

        $this->assertTrue($result->success);
        $this->assertSame('https://pay.sumup.com/hosted/checkout_abc', $result->clientSecret);
        $this->assertSame('checkout_abc', $result->transactionId);
        $this->assertSame('pending', $result->status);
        $this->assertSame('ref_123', $result->reference);

        // The checkout create body must carry the merchant code and hosted checkout enabled.
        $sent = $client->lastRequestBody['POST /v0.1/checkouts'];
        $this->assertSame('MERCHANT1', $sent['merchant_code']);
        $this->assertTrue($sent['hosted_checkout']['enabled']);
    }

    public function test_create_payment_returns_a_failed_result_on_api_error(): void
    {
        $client = new FakeSumUpHttpClient;
        $client->responseQueue['POST /v0.1/checkouts'][] = new ApiException('Validation error', 400, ['message' => 'bad'], 'POST', '/v0.1/checkouts');

        $gateway = $this->gateway($client);

        $request = new PaymentRequest(
            amount: 10.0,
            currency: 'EUR',
            to: new MerchantAccount(
                credentials: ['api_key' => 'sk_live_abc'],
                merchantId: 'MERCHANT1',
            ),
            locale: 'it-IT',
            purchaseCountry: 'IT',
        );

        $result = $gateway->createPayment($request);

        $this->assertFalse($result->success);
        $this->assertSame('failed', $result->status);
    }

    public function test_refund_payment_posts_to_the_refunds_endpoint(): void
    {
        $client = new FakeSumUpHttpClient;
        $client->responses['POST /v1.0/merchants/MERCHANT1/payments/tx_1/refunds'] = [
            'status' => 201,
            'body' => ['id' => 're_1'],
        ];

        $gateway = $this->gateway($client);

        $this->assertTrue($gateway->refundPayment('tx_1', ['api_key' => 'sk_live_abc', 'merchant_code' => 'MERCHANT1'], 5.0));

        // The request body must include the refund amount.
        $sent = $client->lastRequestBody['POST /v1.0/merchants/MERCHANT1/payments/tx_1/refunds'];
        $this->assertSame(5.0, $sent['amount']);
    }

    public function test_refund_payment_full_refund_when_amount_is_null(): void
    {
        $client = new FakeSumUpHttpClient;
        $client->responses['POST /v1.0/merchants/MERCHANT1/payments/tx_1/refunds'] = [
            'status' => 201,
            'body' => ['id' => 're_1'],
        ];

        $gateway = $this->gateway($client);

        $this->assertTrue($gateway->refundPayment('tx_1', ['api_key' => 'sk_live_abc', 'merchant_code' => 'MERCHANT1']));

        // Full refund → no amount is sent.
        $sent = $client->lastRequestBody['POST /v1.0/merchants/MERCHANT1/payments/tx_1/refunds'];
        $this->assertSame([], $sent);
    }

    public function test_refund_payment_returns_false_on_error(): void
    {
        $client = new FakeSumUpHttpClient;
        $client->responseQueue['POST /v1.0/merchants/MERCHANT1/payments/tx_1/refunds'][] = new ApiException('bad', 404, null, 'POST', '/v1.0/merchants/MERCHANT1/payments/tx_1/refunds');

        $gateway = $this->gateway($client);

        $this->assertFalse($gateway->refundPayment('tx_1', ['api_key' => 'sk_live_abc', 'merchant_code' => 'MERCHANT1'], 5.0));
    }

    public function test_deactivate_checkout_calls_the_deactivate_endpoint(): void
    {
        $client = new FakeSumUpHttpClient;
        $client->responses['DELETE /v0.1/checkouts/checkout_abc'] = ['id' => 'checkout_abc'];

        $gateway = $this->gateway($client);

        $this->assertTrue($gateway->deactivateCheckout('checkout_abc', ['api_key' => 'sk_live_abc']));
        $this->assertSame(1, $client->callCounts['DELETE /v0.1/checkouts/checkout_abc']);
    }

    public function test_deactivate_checkout_returns_false_on_error(): void
    {
        $client = new FakeSumUpHttpClient;
        $client->responseQueue['DELETE /v0.1/checkouts/checkout_abc'][] = new ApiException('not found', 404, null, 'DELETE', '/v0.1/checkouts/checkout_abc');

        $gateway = $this->gateway($client);

        $this->assertFalse($gateway->deactivateCheckout('checkout_abc', ['api_key' => 'sk_live_abc']));
    }

    public function test_get_checkout_maps_the_provider_response(): void
    {
        $client = new FakeSumUpHttpClient;
        $client->responses['GET /v0.1/checkouts/checkout_abc'] = [
            'id' => 'checkout_abc',
            'status' => 'PAID',
            'currency' => 'EUR',
            'amount' => 12.5,
            'date' => '2026-08-19T10:00:00Z',
        ];

        $gateway = $this->gateway($client);

        $result = $gateway->getCheckout('checkout_abc', ['api_key' => 'sk_live_abc']);

        $this->assertNotNull($result);
        $this->assertSame('checkout_abc', $result->providerId);
        $this->assertSame('paid', $result->status);
        $this->assertSame('EUR', $result->currency);
        $this->assertSame(12.5, $result->amount);
    }

    public function test_get_checkout_returns_null_on_error(): void
    {
        $client = new FakeSumUpHttpClient;
        $client->responseQueue['GET /v0.1/checkouts/checkout_abc'][] = new ApiException('not found', 404, null, 'GET', '/v0.1/checkouts/checkout_abc');

        $gateway = $this->gateway($client);

        $this->assertNull($gateway->getCheckout('checkout_abc', ['api_key' => 'sk_live_abc']));
    }
}
