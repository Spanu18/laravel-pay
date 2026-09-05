<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Tests\Gateways;

use Illuminate\Support\Facades\Http;
use LaravelPay\LaravelPay\DTO\MerchantAccount;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\Gateways\Edenred;
use LaravelPay\LaravelPay\Tests\Feature\Payments\FakeCheckoutUrls;
use LaravelPay\LaravelPay\Tests\TestCase;
use Psr\Log\NullLogger;

class EdenredTest extends TestCase
{
    private function gateway(): Edenred
    {
        return new Edenred(new NullLogger, new FakeCheckoutUrls);
    }

    private function credentials(): array
    {
        return ['client_id' => 'cid', 'client_secret' => 'secret', 'merchant_id' => 'merch1'];
    }

    public function test_create_credentials_splits_client_id_secret_and_merchant_id(): void
    {
        $result = $this->gateway()->createCredentials('cid|secret|merch1');

        $this->assertSame('merch1', $result['merchant_id']);
        $this->assertSame($this->credentials(), $result['credentials']);
    }

    public function test_create_payment_fetches_a_token_then_creates_the_order(): void
    {
        Http::fake([
            'sso.edenred.it/connect/token' => Http::response(['access_token' => 'tok_1', 'expires_in' => 3600], 200),
            'api.edenred.it/v1/orders' => Http::response([
                'payment_url' => 'https://edenred.it/pay/xyz', 'order_id' => 'ORD1', 'status' => 'pending',
            ], 200),
        ]);

        $request = new PaymentRequest(
            amount: 18.0,
            currency: 'EUR',
            to: new MerchantAccount($this->credentials()),
            locale: 'it-IT',
            purchaseCountry: 'IT',
            orderRef: 4,
            reference: 'cart-hash-edenred-1',
        );

        $result = $this->gateway()->createPayment($request);

        $this->assertTrue($result->success);
        $this->assertSame('https://edenred.it/pay/xyz', $result->clientSecret);
        $this->assertSame('ORD1', $result->transactionId);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/v1/orders') && $req['merchant_id'] === 'merch1');
    }

    public function test_create_payment_returns_a_failed_result_when_the_token_fetch_fails(): void
    {
        Http::fake(['sso.edenred.it/connect/token' => Http::response(['error' => 'invalid_client'], 401)]);

        $request = new PaymentRequest(
            amount: 5.0, currency: 'EUR', to: new MerchantAccount($this->credentials()),
            locale: 'it-IT', purchaseCountry: 'IT',
        );

        $result = $this->gateway()->createPayment($request);

        $this->assertFalse($result->success);
    }

    public function test_refresh_credentials_is_a_noop_for_client_credentials_oauth(): void
    {
        $this->assertSame([], $this->gateway()->refreshCredentials('anything'));
    }
}
