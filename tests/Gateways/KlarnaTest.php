<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Tests\Gateways;

use Illuminate\Support\Facades\Http;
use LaravelPay\LaravelPay\DTO\MerchantAccount;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\Gateways\Klarna;
use LaravelPay\LaravelPay\Tests\Feature\Payments\FakeCheckoutUrls;
use LaravelPay\LaravelPay\Tests\TestCase;
use Psr\Log\NullLogger;

class KlarnaTest extends TestCase
{
    private function gateway(): Klarna
    {
        return new Klarna(true, 'eu', new NullLogger, new FakeCheckoutUrls);
    }

    private function request(array $credentials = ['username' => 'u', 'password' => 'p', 'region' => 'eu']): PaymentRequest
    {
        return new PaymentRequest(
            amount: 30.00,
            currency: 'EUR',
            to: new MerchantAccount($credentials),
            locale: 'it-IT',
            purchaseCountry: 'IT',
            orderRef: 99,
            reference: 'cart-hash-klarna-1',
        );
    }

    public function test_create_credentials_splits_username_password_and_region(): void
    {
        $result = $this->gateway()->createCredentials('user1|pass1|na');

        $this->assertSame('user1', $result['merchant_id']);
        $this->assertSame(['username' => 'user1', 'password' => 'pass1', 'region' => 'na'], $result['credentials']);
    }

    public function test_create_credentials_defaults_region_to_eu_when_omitted(): void
    {
        $result = $this->gateway()->createCredentials('user1|pass1');

        $this->assertSame('eu', $result['credentials']['region']);
    }

    public function test_create_payment_creates_a_session_then_an_hpp_session_and_returns_the_redirect_url(): void
    {
        Http::fake([
            'api.playground.klarna.com/payments/v1/sessions' => Http::response(['session_id' => 'sess_123'], 200),
            'api.playground.klarna.com/hpp/v1/sessions' => Http::response(['redirect_url' => 'https://hpp.klarna.com/xyz'], 200),
        ]);

        $result = $this->gateway()->createPayment($this->request());

        $this->assertTrue($result->success);
        $this->assertSame('https://hpp.klarna.com/xyz', $result->clientSecret);
        $this->assertSame('sess_123', $result->transactionId);

        Http::assertSent(function ($req) {
            return str_contains($req->url(), '/payments/v1/sessions')
                && $req['purchase_country'] === 'IT'
                && $req['locale'] === 'it-IT'
                && $req['order_amount'] === 3000;
        });

        Http::assertSent(function ($req) {
            return str_contains($req->url(), '/hpp/v1/sessions')
                && str_contains($req['merchant_urls']['success'], 'authorization_token={{authorization_token}}');
        });
    }

    public function test_create_payment_returns_a_failed_result_when_the_session_call_fails(): void
    {
        Http::fake([
            'api.playground.klarna.com/payments/v1/sessions' => Http::response(['error_message' => 'nope'], 400),
        ]);

        $result = $this->gateway()->createPayment($this->request());

        $this->assertFalse($result->success);
        $this->assertSame('failed', $result->status);
    }

    public function test_create_order_exchanges_the_authorization_token_for_a_klarna_order_id(): void
    {
        Http::fake([
            'api.playground.klarna.com/payments/v1/authorizations/*' => Http::response(['order_id' => 'kco_order_1'], 200),
        ]);

        $orderId = $this->gateway()->createOrder($this->request(), 'auth_tok_abc');

        $this->assertSame('kco_order_1', $orderId);
    }

    public function test_capture_order_posts_the_captured_amount_in_minor_units(): void
    {
        Http::fake([
            'api.playground.klarna.com/ordermanagement/v1/orders/*/captures' => Http::response([], 200),
        ]);

        $captured = $this->gateway()->captureOrder('kco_order_1', ['region' => 'eu'], 30.00);

        $this->assertTrue($captured);
        Http::assertSent(fn ($req) => $req['captured_amount'] === 3000);
    }

    public function test_refresh_credentials_is_a_noop_for_static_username_password_credentials(): void
    {
        $this->assertSame([], $this->gateway()->refreshCredentials('anything'));
    }
}
