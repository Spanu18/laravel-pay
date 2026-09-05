<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Tests\Gateways;

use LaravelPay\LaravelPay\DTO\MerchantAccount;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\Gateways\Nexi;
use LaravelPay\LaravelPay\Tests\Feature\Payments\FakeCheckoutUrls;
use LaravelPay\LaravelPay\Tests\TestCase;
use Psr\Log\NullLogger;

class NexiTest extends TestCase
{
    private function gateway(bool $sandbox = true): Nexi
    {
        return new Nexi($sandbox, new NullLogger, new FakeCheckoutUrls);
    }

    public function test_create_credentials_splits_alias_and_mac_key(): void
    {
        $result = $this->gateway()->createCredentials('my-alias|my-mac-key');

        $this->assertSame('my-alias', $result['merchant_id']);
        $this->assertSame(['alias' => 'my-alias', 'mac_key' => 'my-mac-key'], $result['credentials']);
    }

    public function test_create_payment_builds_a_sandbox_redirect_url_with_a_valid_mac(): void
    {
        $request = new PaymentRequest(
            amount: 25.00,
            currency: 'EUR',
            to: new MerchantAccount(['alias' => 'my-alias', 'mac_key' => 'secret']),
            locale: 'it-IT',
            purchaseCountry: 'IT',
            reference: 'cart-hash-0123456789abcdef',
        );

        $result = $this->gateway(sandbox: true)->createPayment($request);

        $this->assertTrue($result->success);
        $this->assertStringStartsWith('https://int-ecommerce.nexi.it/ecomm/ecomm/XPayLight?', $result->clientSecret);
        $this->assertSame('cart-hash-0123456789abcdef', $result->reference);

        parse_str(parse_url($result->clientSecret, PHP_URL_QUERY), $params);
        $this->assertSame('my-alias', $params['alias']);
        $this->assertSame('2500', $params['importo']);
        $this->assertSame('EUR', $params['divisa']);
        $expectedMac = hash('sha1', "codTrans={$params['codTrans']}divisa=EURimporto=2500secret");
        $this->assertSame($expectedMac, $params['mac']);
    }

    public function test_create_payment_uses_live_url_when_not_sandbox(): void
    {
        $request = new PaymentRequest(
            amount: 10.00,
            currency: 'EUR',
            to: new MerchantAccount(['alias' => 'a', 'mac_key' => 'k']),
            locale: 'it-IT',
            purchaseCountry: 'IT',
        );

        $result = $this->gateway(sandbox: false)->createPayment($request);

        $this->assertStringStartsWith('https://ecommerce.nexi.it/ecomm/ecomm/XPayLight?', $result->clientSecret);
    }

    public function test_refund_payment_is_not_automated_and_returns_false(): void
    {
        $this->assertFalse($this->gateway()->refundPayment('codTrans1', ['alias' => 'a', 'mac_key' => 'k'], 5.0));
    }

    public function test_deactivate_checkout_is_a_noop_that_returns_true(): void
    {
        $this->assertTrue($this->gateway()->deactivateCheckout('codTrans1', ['alias' => 'a', 'mac_key' => 'k']));
    }

    public function test_refresh_credentials_is_a_noop_for_static_credentials(): void
    {
        $this->assertSame([], $this->gateway()->refreshCredentials('anything'));
    }
}
