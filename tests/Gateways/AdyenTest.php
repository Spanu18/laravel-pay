<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Tests\Gateways;

use Illuminate\Support\Facades\Http;
use LaravelPay\LaravelPay\DTO\MerchantAccount;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\Gateways\Adyen;
use LaravelPay\LaravelPay\Tests\Feature\Payments\FakeCheckoutUrls;
use LaravelPay\LaravelPay\Tests\TestCase;
use Psr\Log\NullLogger;

class AdyenTest extends TestCase
{
    private function gateway(bool $live = false): Adyen
    {
        return new Adyen($live, new NullLogger, new FakeCheckoutUrls);
    }

    private function credentials(): array
    {
        return ['api_key' => 'sk_x', 'merchant_account' => 'MerchAcc', 'client_key' => 'ck_x'];
    }

    public function test_create_credentials_splits_api_key_merchant_account_and_client_key(): void
    {
        $result = $this->gateway()->createCredentials('sk_x|MerchAcc|ck_x');

        $this->assertSame('MerchAcc', $result['merchant_id']);
        $this->assertSame(
            ['api_key' => 'sk_x', 'merchant_account' => 'MerchAcc', 'client_key' => 'ck_x'],
            $result['credentials'],
        );
    }

    public function test_create_payment_returns_the_payment_link_url_on_success(): void
    {
        Http::fake([
            'checkout-test.adyen.com/v71/paymentLinks' => Http::response([
                'id' => 'LINK123', 'url' => 'https://test.adyen.link/LINK123', 'status' => 'active',
            ], 200),
        ]);

        $request = new PaymentRequest(
            amount: 25.00,
            currency: 'EUR',
            to: new MerchantAccount($this->credentials()),
            locale: 'it-IT',
            purchaseCountry: 'IT',
            orderRef: 42,
            reference: 'cart-hash-adyen-1',
        );

        $result = $this->gateway()->createPayment($request);

        $this->assertTrue($result->success);
        $this->assertSame('https://test.adyen.link/LINK123', $result->clientSecret);
        $this->assertSame('LINK123', $result->transactionId);
        $this->assertSame('cart-hash-adyen-1', $result->reference);

        Http::assertSent(function ($req) {
            return $req['merchantAccount'] === 'MerchAcc'
                && $req['amount']['value'] === 2500
                && $req['amount']['currency'] === 'EUR';
        });
    }

    public function test_create_payment_returns_a_failed_result_on_http_error(): void
    {
        Http::fake(['checkout-test.adyen.com/*' => Http::response(['message' => 'nope'], 422)]);

        $request = new PaymentRequest(
            amount: 10.00,
            currency: 'EUR',
            to: new MerchantAccount($this->credentials()),
            locale: 'it-IT',
            purchaseCountry: 'IT',
        );

        $result = $this->gateway()->createPayment($request);

        $this->assertFalse($result->success);
        $this->assertSame('failed', $result->status);
    }

    public function test_refund_payment_posts_to_the_refunds_endpoint(): void
    {
        Http::fake(['checkout-test.adyen.com/v68/payments/*/refunds' => Http::response([], 200)]);

        $this->assertTrue($this->gateway()->refundPayment('PSP123', $this->credentials(), 5.0));
    }

    public function test_refresh_credentials_is_a_noop_for_static_credentials(): void
    {
        $this->assertSame([], $this->gateway()->refreshCredentials('anything'));
    }
}
