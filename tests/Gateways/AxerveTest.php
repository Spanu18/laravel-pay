<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Tests\Gateways;

use Illuminate\Support\Facades\Http;
use LaravelPay\LaravelPay\DTO\MerchantAccount;
use LaravelPay\LaravelPay\DTO\PayerContact;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\Gateways\Axerve;
use LaravelPay\LaravelPay\Tests\TestCase;
use Psr\Log\NullLogger;

class AxerveTest extends TestCase
{
    private function gateway(bool $sandbox = true): Axerve
    {
        return new Axerve($sandbox, new NullLogger);
    }

    private function credentials(): array
    {
        return ['shop_login' => 'shop1', 'api_key' => 'key1'];
    }

    public function test_create_credentials_splits_shop_login_and_api_key(): void
    {
        $result = $this->gateway()->createCredentials('shop1|key1');

        $this->assertSame('shop1', $result['merchant_id']);
        $this->assertSame(['shop_login' => 'shop1', 'api_key' => 'key1'], $result['credentials']);
    }

    public function test_create_payment_returns_the_redirect_url_and_bank_transaction_id(): void
    {
        Http::fake([
            'sandbox.gestpay.net/api/v1/payment/create' => Http::response([
                'error' => ['code' => '0'],
                'payload' => ['userRedirect' => ['href' => 'https://pay.gestpay.it/xyz'], 'bankTransactionID' => 'BT123'],
            ], 200),
        ]);

        $request = new PaymentRequest(
            amount: 30.0,
            currency: 'EUR',
            to: new MerchantAccount($this->credentials()),
            locale: 'it-IT',
            purchaseCountry: 'IT',
            from: new PayerContact(name: 'Mario', email: 'mario@example.com'),
            orderRef: 7,
            reference: 'cart-hash-axerve-1',
        );

        $result = $this->gateway()->createPayment($request);

        $this->assertTrue($result->success);
        $this->assertSame('https://pay.gestpay.it/xyz', $result->clientSecret);
        $this->assertSame('BT123', $result->transactionId);
        $this->assertSame('cart-hash-axerve-1', $result->reference);

        Http::assertSent(fn ($req) => $req['buyerName'] === 'Mario' && $req['buyerEmail'] === 'mario@example.com');
    }

    public function test_create_payment_returns_a_failed_result_when_the_api_reports_an_error(): void
    {
        Http::fake([
            'sandbox.gestpay.net/api/v1/payment/create' => Http::response(['error' => ['code' => '5']], 200),
        ]);

        $request = new PaymentRequest(
            amount: 10.0, currency: 'EUR', to: new MerchantAccount($this->credentials()),
            locale: 'it-IT', purchaseCountry: 'IT',
        );

        $result = $this->gateway()->createPayment($request);

        $this->assertFalse($result->success);
    }

    public function test_refresh_credentials_is_a_noop_for_static_credentials(): void
    {
        $this->assertSame([], $this->gateway()->refreshCredentials('anything'));
    }
}
