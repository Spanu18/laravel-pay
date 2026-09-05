<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Tests\Gateways;

use Illuminate\Support\Facades\Http;
use LaravelPay\LaravelPay\DTO\MerchantAccount;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\Gateways\Revolut;
use LaravelPay\LaravelPay\Tests\TestCase;
use Psr\Log\NullLogger;

class RevolutTest extends TestCase
{
    private function gateway(): Revolut
    {
        return new Revolut(new NullLogger);
    }

    public function test_create_credentials_splits_private_and_public_key(): void
    {
        $result = $this->gateway()->createCredentials('priv_1:pub_1');

        $this->assertSame(['public_key' => 'pub_1', 'private_key' => 'priv_1'], $result['credentials']);
    }

    public function test_create_payment_returns_the_checkout_token(): void
    {
        Http::fake([
            'sandbox-merchant.revolut.com/api/orders' => Http::response(['token' => 'rev_tok_1', 'state' => 'pending'], 201),
        ]);

        $request = new PaymentRequest(
            amount: 17.0,
            currency: 'EUR',
            to: new MerchantAccount(['public_key' => 'pub_1', 'private_key' => 'priv_1']),
            locale: 'it-IT',
            purchaseCountry: 'IT',
            orderRef: 2,
            reference: 'cart-hash-revolut-1',
        );

        $result = $this->gateway()->createPayment($request);

        $this->assertTrue($result->success);
        $this->assertSame('rev_tok_1', $result->clientSecret);
        $this->assertSame('rev_tok_1', $result->transactionId);
        $this->assertSame('pending', $result->status);
    }

    public function test_refund_payment_omits_the_amount_field_when_null(): void
    {
        Http::fake(['sandbox-merchant.revolut.com/api/orders/*/refund' => Http::response([], 200)]);

        $this->assertTrue($this->gateway()->refundPayment('rev_tok_1', ['public_key' => 'p', 'private_key' => 'k']));

        Http::assertSent(fn ($req) => ! array_key_exists('amount', $req->data()));
    }

    public function test_refresh_credentials_is_a_noop_for_static_credentials(): void
    {
        $this->assertSame([], $this->gateway()->refreshCredentials('anything'));
    }
}
