<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Tests\Gateways;

use Illuminate\Support\Facades\Http;
use LaravelPay\LaravelPay\DTO\MerchantAccount;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\Gateways\Pluxee;
use LaravelPay\LaravelPay\Tests\Feature\Payments\FakeCheckoutUrls;
use LaravelPay\LaravelPay\Tests\TestCase;
use Psr\Log\NullLogger;

class PluxeeTest extends TestCase
{
    private function gateway(): Pluxee
    {
        return new Pluxee(new NullLogger, new FakeCheckoutUrls);
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

    public function test_create_payment_fetches_a_token_then_creates_the_payment(): void
    {
        Http::fake([
            'auth.pluxee.com/oauth/token' => Http::response(['access_token' => 'tok_1', 'expires_in' => 3600], 200),
            'api.pluxee.com/v1/payments' => Http::response([
                'payment_url' => 'https://pluxee.com/pay/xyz', 'payment_id' => 'PAY1', 'status' => 'pending',
            ], 200),
        ]);

        $request = new PaymentRequest(
            amount: 16.0,
            currency: 'EUR',
            to: new MerchantAccount($this->credentials()),
            locale: 'it-IT',
            purchaseCountry: 'IT',
            orderRef: 11,
            reference: 'cart-hash-pluxee-1',
        );

        $result = $this->gateway()->createPayment($request);

        $this->assertTrue($result->success);
        $this->assertSame('https://pluxee.com/pay/xyz', $result->clientSecret);
        $this->assertSame('PAY1', $result->transactionId);
    }

    public function test_refresh_credentials_is_a_noop_for_client_credentials_oauth(): void
    {
        $this->assertSame([], $this->gateway()->refreshCredentials('anything'));
    }
}
