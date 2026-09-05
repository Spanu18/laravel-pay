<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Tests\Gateways;

use Illuminate\Support\Facades\Http;
use LaravelPay\LaravelPay\DTO\MerchantAccount;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\Gateways\PayPal;
use LaravelPay\LaravelPay\Tests\Feature\Payments\FakeCheckoutUrls;
use LaravelPay\LaravelPay\Tests\TestCase;
use Psr\Log\NullLogger;

class PayPalTest extends TestCase
{
    private function gateway(bool $sandbox = true): PayPal
    {
        return new PayPal($sandbox, new NullLogger, new FakeCheckoutUrls);
    }

    public function test_create_credentials_splits_client_id_and_secret(): void
    {
        $result = $this->gateway()->createCredentials('cid1|secret1');

        $this->assertSame('cid1', $result['merchant_id']);
        $this->assertSame(['client_id' => 'cid1', 'secret' => 'secret1'], $result['credentials']);
    }

    public function test_create_payment_returns_the_approve_link(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'bearer_1'], 200),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'ORDER1',
                'status' => 'CREATED',
                'links' => [['rel' => 'approve', 'href' => 'https://paypal.com/checkoutnow?token=ORDER1']],
            ], 201),
        ]);

        $request = new PaymentRequest(
            amount: 14.0,
            currency: 'EUR',
            to: new MerchantAccount(['client_id' => 'cid1', 'secret' => 'secret1']),
            locale: 'it-IT',
            purchaseCountry: 'IT',
            orderRef: 6,
            reference: 'cart-hash-paypal-1',
        );

        $result = $this->gateway()->createPayment($request);

        $this->assertTrue($result->success);
        $this->assertSame('https://paypal.com/checkoutnow?token=ORDER1', $result->clientSecret);
        $this->assertSame('ORDER1', $result->transactionId);
        $this->assertSame('created', $result->status);
    }

    public function test_deactivate_checkout_is_a_noop_that_returns_true(): void
    {
        $this->assertTrue($this->gateway()->deactivateCheckout('anything', ['client_id' => 'c', 'secret' => 's']));
    }

    public function test_refresh_credentials_is_a_noop_for_client_credentials_oauth(): void
    {
        $this->assertSame([], $this->gateway()->refreshCredentials('anything'));
    }
}
