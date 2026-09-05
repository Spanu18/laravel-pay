<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Tests\Gateways;

use Illuminate\Support\Facades\Http;
use LaravelPay\LaravelPay\DTO\MerchantAccount;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\Gateways\BinancePay;
use LaravelPay\LaravelPay\Tests\Feature\Payments\FakeCheckoutUrls;
use LaravelPay\LaravelPay\Tests\TestCase;
use Psr\Log\NullLogger;

class BinancePayTest extends TestCase
{
    private function gateway(): BinancePay
    {
        return new BinancePay(new NullLogger, new FakeCheckoutUrls);
    }

    public function test_create_credentials_splits_api_key_and_api_secret(): void
    {
        $result = $this->gateway()->createCredentials('key1|secret1');

        $this->assertSame(['api_key' => 'key1', 'api_secret' => 'secret1'], $result['credentials']);
    }

    public function test_create_payment_returns_the_checkout_url_on_success(): void
    {
        Http::fake([
            'bpay.binanceapi.com/binancepay/openapi/v3/order' => Http::response([
                'status' => 'SUCCESS',
                'data' => ['checkoutUrl' => 'https://pay.binance.com/checkout/xyz', 'prepayId' => 'PREPAY123'],
            ], 200),
        ]);

        $request = new PaymentRequest(
            amount: 12.0,
            currency: 'EUR',
            to: new MerchantAccount(['api_key' => 'key1', 'api_secret' => 'secret1']),
            locale: 'it-IT',
            purchaseCountry: 'IT',
            orderRef: 9,
            reference: 'cart-hash-binance-1',
        );

        $result = $this->gateway()->createPayment($request);

        $this->assertTrue($result->success);
        $this->assertSame('https://pay.binance.com/checkout/xyz', $result->clientSecret);
        $this->assertSame('PREPAY123', $result->transactionId);
        $this->assertSame('pending', $result->status);
    }

    public function test_create_payment_returns_a_failed_result_when_the_api_reports_failure(): void
    {
        Http::fake(['bpay.binanceapi.com/*' => Http::response(['status' => 'FAIL'], 200)]);

        $request = new PaymentRequest(
            amount: 5.0, currency: 'EUR', to: new MerchantAccount(['api_key' => 'k', 'api_secret' => 's']),
            locale: 'it-IT', purchaseCountry: 'IT',
        );

        $result = $this->gateway()->createPayment($request);

        $this->assertFalse($result->success);
    }

    public function test_verify_webhook_signature_returns_false_on_malformed_headers(): void
    {
        $this->assertFalse($this->gateway()->verifyWebhookSignature('{}', '', '', '', ['api_key' => 'k', 'api_secret' => 's']));
    }

    public function test_refresh_credentials_is_a_noop_for_static_credentials(): void
    {
        $this->assertSame([], $this->gateway()->refreshCredentials('anything'));
    }
}
