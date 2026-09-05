<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Tests\Gateways;

use Illuminate\Support\Facades\Http;
use LaravelPay\LaravelPay\DTO\MerchantAccount;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\Gateways\Coinbase;
use LaravelPay\LaravelPay\Tests\Feature\Payments\FakeCheckoutUrls;
use LaravelPay\LaravelPay\Tests\TestCase;
use Psr\Log\NullLogger;

class CoinbaseTest extends TestCase
{
    private function gateway(): Coinbase
    {
        return new Coinbase('2018-03-22', new NullLogger, new FakeCheckoutUrls);
    }

    public function test_create_credentials_splits_api_key_and_webhook_secret(): void
    {
        $result = $this->gateway()->createCredentials('key1|secret1');

        $this->assertSame(['api_key' => 'key1', 'webhook_secret' => 'secret1'], $result['credentials']);
    }

    public function test_create_payment_returns_the_hosted_checkout_url(): void
    {
        Http::fake([
            'api.commerce.coinbase.com/charges' => Http::response([
                'data' => ['hosted_url' => 'https://commerce.coinbase.com/charges/ABC', 'code' => 'ABC', 'timeline' => []],
            ], 201),
        ]);

        $request = new PaymentRequest(
            amount: 15.0,
            currency: 'EUR',
            to: new MerchantAccount(['api_key' => 'key1']),
            locale: 'it-IT',
            purchaseCountry: 'IT',
            orderRef: 3,
            reference: 'cart-hash-coinbase-1',
        );

        $result = $this->gateway()->createPayment($request);

        $this->assertTrue($result->success);
        $this->assertSame('https://commerce.coinbase.com/charges/ABC', $result->clientSecret);
        $this->assertSame('ABC', $result->transactionId);
    }

    public function test_deactivate_checkout_posts_to_the_cancel_endpoint(): void
    {
        Http::fake(['api.commerce.coinbase.com/charges/*/cancel' => Http::response([], 200)]);

        $this->assertTrue($this->gateway()->deactivateCheckout('ABC', ['api_key' => 'key1']));
    }

    public function test_refresh_credentials_is_a_noop_for_static_credentials(): void
    {
        $this->assertSame([], $this->gateway()->refreshCredentials('anything'));
    }
}
