<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Tests\Gateways;

use Illuminate\Support\Facades\Http;
use LaravelPay\LaravelPay\DTO\MerchantAccount;
use LaravelPay\LaravelPay\DTO\PayerContact;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\Gateways\Scalapay;
use LaravelPay\LaravelPay\Tests\Feature\Payments\FakeCheckoutUrls;
use LaravelPay\LaravelPay\Tests\TestCase;
use Psr\Log\NullLogger;

class ScalapayTest extends TestCase
{
    private function gateway(bool $sandbox = true): Scalapay
    {
        return new Scalapay($sandbox, new NullLogger, new FakeCheckoutUrls);
    }

    public function test_create_credentials_stores_the_raw_code_as_the_api_key(): void
    {
        $result = $this->gateway()->createCredentials('sp_live_abc');

        $this->assertSame(['api_key' => 'sp_live_abc'], $result['credentials']);
    }

    public function test_create_payment_returns_the_checkout_url_and_token(): void
    {
        Http::fake([
            'integration.api.scalapay.com/v2/orders' => Http::response([
                'token' => 'SP_TOKEN_1', 'checkoutUrl' => 'https://checkout.scalapay.com/SP_TOKEN_1', 'status' => 'created',
            ], 201),
        ]);

        $request = new PaymentRequest(
            amount: 40.0,
            currency: 'EUR',
            to: new MerchantAccount(['api_key' => 'sp_live_abc']),
            locale: 'it-IT',
            purchaseCountry: 'IT',
            from: new PayerContact(name: 'Mario', surname: 'Rossi', email: 'mario@example.com'),
            orderRef: 21,
            reference: 'cart-hash-scalapay-1',
        );

        $result = $this->gateway()->createPayment($request);

        $this->assertTrue($result->success);
        $this->assertSame('https://checkout.scalapay.com/SP_TOKEN_1', $result->clientSecret);
        $this->assertSame('SP_TOKEN_1', $result->transactionId);

        Http::assertSent(fn ($req) => $req['consumer']['givenNames'] === 'Mario' && $req['consumer']['email'] === 'mario@example.com');
    }

    public function test_capture_order_posts_the_token(): void
    {
        Http::fake(['integration.api.scalapay.com/v2/payments/capture' => Http::response([], 200)]);

        $this->assertTrue($this->gateway()->captureOrder('SP_TOKEN_1', ['api_key' => 'sp_live_abc'], 40.0));

        Http::assertSent(fn ($req) => $req['token'] === 'SP_TOKEN_1');
    }

    public function test_capture_order_returns_false_on_failure(): void
    {
        Http::fake(['integration.api.scalapay.com/v2/payments/capture' => Http::response(['message' => 'denied'], 422)]);

        $this->assertFalse($this->gateway()->captureOrder('SP_TOKEN_1', ['api_key' => 'sp_live_abc'], 40.0));
    }

    public function test_refresh_credentials_is_a_noop_for_a_static_api_key(): void
    {
        $this->assertSame([], $this->gateway()->refreshCredentials('anything'));
    }
}
