<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Tests\Gateways;

use Illuminate\Support\Facades\Http;
use LaravelPay\LaravelPay\DTO\MerchantAccount;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\Gateways\Mollie;
use LaravelPay\LaravelPay\Tests\Feature\Payments\FakeCheckoutUrls;
use LaravelPay\LaravelPay\Tests\TestCase;
use Psr\Log\NullLogger;

class MollieTest extends TestCase
{
    private function gateway(): Mollie
    {
        return new Mollie('client_id_1', 'client_secret_1', new NullLogger, new FakeCheckoutUrls);
    }

    public function test_create_credentials_exchanges_the_code_and_fetches_the_organization(): void
    {
        Http::fake([
            'api.mollie.com/oauth2/tokens' => Http::response([
                'access_token' => 'acc_1', 'refresh_token' => 'ref_1', 'expires_in' => 3600,
            ], 200),
            'api.mollie.com/v2/organizations/me' => Http::response(['id' => 'org_1', 'email' => 'merchant@example.com'], 200),
        ]);

        $result = $this->gateway()->createCredentials('auth_code_xyz');

        $this->assertSame('org_1', $result['merchant_id']);
        $this->assertSame('merchant@example.com', $result['email']);
        $this->assertSame('acc_1', $result['credentials']['access_token']);
        $this->assertSame('ref_1', $result['credentials']['refresh_token']);
    }

    public function test_create_payment_returns_the_checkout_url_on_success(): void
    {
        Http::fake([
            'api.mollie.com/v2/payments' => Http::response([
                'id' => 'tr_1', 'status' => 'open', '_links' => ['checkout' => ['href' => 'https://mollie.com/checkout/tr_1']],
            ], 201),
        ]);

        $request = new PaymentRequest(
            amount: 22.0,
            currency: 'EUR',
            to: new MerchantAccount(['access_token' => 'acc_1', 'refresh_token' => 'ref_1']),
            locale: 'it-IT',
            purchaseCountry: 'IT',
            orderRef: 8,
            reference: 'cart-hash-mollie-1',
        );

        $result = $this->gateway()->createPayment($request);

        $this->assertTrue($result->success);
        $this->assertSame('https://mollie.com/checkout/tr_1', $result->clientSecret);
        $this->assertSame('tr_1', $result->transactionId);
        $this->assertNull($result->refreshedCredentials);
    }

    public function test_create_payment_refreshes_and_retries_once_on_401(): void
    {
        Http::fakeSequence('api.mollie.com/v2/payments')
            ->push(['status' => 'unauthorized'], 401)
            ->push([
                'id' => 'tr_2', 'status' => 'open', '_links' => ['checkout' => ['href' => 'https://mollie.com/checkout/tr_2']],
            ], 201);

        Http::fake([
            'api.mollie.com/oauth2/tokens' => Http::response([
                'access_token' => 'acc_new', 'refresh_token' => 'ref_new', 'expires_in' => 3600,
            ], 200),
            'api.mollie.com/v2/payments' => Http::sequence()
                ->push(['status' => 'unauthorized'], 401)
                ->push([
                    'id' => 'tr_2', 'status' => 'open', '_links' => ['checkout' => ['href' => 'https://mollie.com/checkout/tr_2']],
                ], 201),
        ]);

        $request = new PaymentRequest(
            amount: 9.0,
            currency: 'EUR',
            to: new MerchantAccount(['access_token' => 'acc_stale', 'refresh_token' => 'ref_1']),
            locale: 'it-IT',
            purchaseCountry: 'IT',
            reference: 'cart-hash-mollie-2',
        );

        $result = $this->gateway()->createPayment($request);

        $this->assertTrue($result->success);
        $this->assertSame('tr_2', $result->transactionId);
        $this->assertSame('acc_new', $result->refreshedCredentials['access_token']);
    }

    public function test_refresh_credentials_returns_empty_array_on_failure(): void
    {
        Http::fake(['api.mollie.com/oauth2/tokens' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->assertSame([], $this->gateway()->refreshCredentials('bad_token'));
    }

    public function test_refund_payment_refreshes_and_retries_once_on_401(): void
    {
        Http::fake([
            'api.mollie.com/oauth2/tokens' => Http::response([
                'access_token' => 'acc_new', 'refresh_token' => 'ref_new', 'expires_in' => 3600,
            ], 200),
            'api.mollie.com/v2/payments/*/refunds' => Http::sequence()
                ->push(['status' => 'unauthorized'], 401)
                ->push(['id' => 're_1', 'status' => 'pending'], 201),
        ]);

        $result = $this->gateway()->refundPayment('tr_1', ['access_token' => 'acc_stale', 'refresh_token' => 'ref_1'], 5.0);

        $this->assertTrue($result);
    }

    public function test_refund_payment_fails_on_401_with_no_refresh_token(): void
    {
        Http::fake([
            'api.mollie.com/v2/payments/*/refunds' => Http::response(['status' => 'unauthorized'], 401),
        ]);

        $result = $this->gateway()->refundPayment('tr_1', ['access_token' => 'acc_stale'], 5.0);

        $this->assertFalse($result);
    }

    public function test_deactivate_checkout_refreshes_and_retries_once_on_401(): void
    {
        Http::fake([
            'api.mollie.com/oauth2/tokens' => Http::response([
                'access_token' => 'acc_new', 'refresh_token' => 'ref_new', 'expires_in' => 3600,
            ], 200),
            'api.mollie.com/v2/payments/*' => Http::sequence()
                ->push(['status' => 'unauthorized'], 401)
                ->push([], 204),
        ]);

        $result = $this->gateway()->deactivateCheckout('tr_1', ['access_token' => 'acc_stale', 'refresh_token' => 'ref_1']);

        $this->assertTrue($result);
    }

    public function test_get_checkout_refreshes_and_retries_once_on_401(): void
    {
        Http::fake([
            'api.mollie.com/oauth2/tokens' => Http::response([
                'access_token' => 'acc_new', 'refresh_token' => 'ref_new', 'expires_in' => 3600,
            ], 200),
            'api.mollie.com/v2/payments/*' => Http::sequence()
                ->push(['status' => 'unauthorized'], 401)
                ->push([
                    'id' => 'tr_1', 'status' => 'paid', 'amount' => ['currency' => 'EUR', 'value' => '9.00'], 'createdAt' => '2026-01-01T00:00:00Z',
                ], 200),
        ]);

        $result = $this->gateway()->getCheckout('tr_1', ['access_token' => 'acc_stale', 'refresh_token' => 'ref_1']);

        $this->assertNotNull($result);
        $this->assertSame('paid', $result->status);
    }
}
