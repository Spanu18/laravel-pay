<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Tests\Gateways;

use Illuminate\Support\Facades\Http;
use LaravelPay\LaravelPay\DTO\MerchantAccount;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\Gateways\Braintree;
use LaravelPay\LaravelPay\Tests\TestCase;
use Psr\Log\NullLogger;

class BraintreeTest extends TestCase
{
    private function gateway(bool $sandbox = true): Braintree
    {
        return new Braintree('client_id_1', 'client_secret_1', $sandbox, new NullLogger);
    }

    public function test_create_credentials_exchanges_the_authorization_code(): void
    {
        Http::fake([
            'api.sandbox.braintreegateway.com/oauth/access_tokens' => Http::response([
                'access_token' => 'acc_1', 'refresh_token' => 'ref_1', 'merchant_id' => 'merch_1', 'expires_in' => 7776000,
            ], 200),
        ]);

        $result = $this->gateway()->createCredentials('auth_code_xyz');

        $this->assertSame('merch_1', $result['merchant_id']);
        $this->assertSame('acc_1', $result['credentials']['access_token']);
        $this->assertSame('ref_1', $result['credentials']['refresh_token']);
    }

    public function test_refresh_credentials_exchanges_the_refresh_token(): void
    {
        Http::fake([
            'api.sandbox.braintreegateway.com/oauth/access_tokens' => Http::response([
                'access_token' => 'acc_new', 'refresh_token' => 'ref_new', 'expires_in' => 7776000,
            ], 200),
        ]);

        $result = $this->gateway()->refreshCredentials('ref_old');

        $this->assertSame('acc_new', $result['access_token']);
        $this->assertSame('ref_new', $result['refresh_token']);
    }

    public function test_refresh_credentials_returns_empty_array_on_failure(): void
    {
        Http::fake(['api.sandbox.braintreegateway.com/oauth/access_tokens' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->assertSame([], $this->gateway()->refreshCredentials('bad_token'));
    }

    public function test_create_payment_returns_a_client_token_as_the_client_secret(): void
    {
        Http::fake([
            'payments.sandbox.braintree-api.com/graphql' => Http::response([
                'data' => ['createClientToken' => ['clientToken' => 'ctok_123']],
            ], 200),
        ]);

        $request = new PaymentRequest(
            amount: 20.0,
            currency: 'EUR',
            to: new MerchantAccount(['access_token' => 'acc_1', 'merchant_id' => 'merch_1']),
            locale: 'it-IT',
            purchaseCountry: 'IT',
            reference: 'cart-hash-braintree-1',
        );

        $result = $this->gateway()->createPayment($request);

        $this->assertTrue($result->success);
        $this->assertSame('ctok_123', $result->clientSecret);
        $this->assertSame('pending_nonce', $result->status);
    }

    public function test_deactivate_checkout_is_a_noop_that_returns_true(): void
    {
        $this->assertTrue($this->gateway()->deactivateCheckout('anything', ['access_token' => 'acc_1']));
    }
}
