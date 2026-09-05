<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Tests\Gateways;

use Illuminate\Support\Facades\Http;
use LaravelPay\LaravelPay\DTO\MerchantAccount;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\Gateways\Satispay;
use LaravelPay\LaravelPay\Tests\Feature\Payments\FakeCheckoutUrls;
use LaravelPay\LaravelPay\Tests\TestCase;
use Psr\Log\NullLogger;

class SatispayTest extends TestCase
{
    private function gateway(bool $sandbox = true): Satispay
    {
        return new Satispay($sandbox, new NullLogger, new FakeCheckoutUrls);
    }

    public function test_create_credentials_stores_the_raw_code_as_the_bearer_token(): void
    {
        $result = $this->gateway()->createCredentials('bearer_tok_abc');

        $this->assertSame(['token' => 'bearer_tok_abc'], $result['credentials']);
    }

    public function test_create_payment_returns_the_redirect_url(): void
    {
        Http::fake([
            'staging.online.satispay.com/v1/payments' => Http::response([
                'id' => 'SATIS1', 'status' => 'pending', 'redirect_url' => 'https://online.satispay.com/pay/SATIS1',
            ], 200),
        ]);

        $request = new PaymentRequest(
            amount: 8.0,
            currency: 'EUR',
            to: new MerchantAccount(['token' => 'bearer_tok_abc']),
            locale: 'it-IT',
            purchaseCountry: 'IT',
            orderRef: 5,
            reference: 'cart-hash-satispay-1',
        );

        $result = $this->gateway()->createPayment($request);

        $this->assertTrue($result->success);
        $this->assertSame('https://online.satispay.com/pay/SATIS1', $result->clientSecret);
        $this->assertSame('SATIS1', $result->transactionId);
    }

    public function test_deactivate_checkout_puts_action_cancel(): void
    {
        Http::fake(['staging.online.satispay.com/v1/payments/*' => Http::response([], 200)]);

        $this->assertTrue($this->gateway()->deactivateCheckout('SATIS1', ['token' => 'bearer_tok_abc']));

        Http::assertSent(fn ($req) => ($req->data()['action'] ?? null) === 'CANCEL');
    }

    public function test_refresh_credentials_is_a_noop_for_a_static_merchant_token(): void
    {
        $this->assertSame([], $this->gateway()->refreshCredentials('anything'));
    }
}
