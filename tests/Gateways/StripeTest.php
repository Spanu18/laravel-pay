<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Tests\Gateways;

use LaravelPay\LaravelPay\DTO\MerchantAccount;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\Gateways\Stripe;
use LaravelPay\LaravelPay\Tests\Feature\Payments\FakeCheckoutUrls;
use LaravelPay\LaravelPay\Tests\TestCase;
use Psr\Log\NullLogger;

class StripeTest extends TestCase
{
    private function gateway(?string $platformSecretKey = 'sk_platform_test'): Stripe
    {
        return new Stripe($platformSecretKey, new NullLogger, new FakeCheckoutUrls);
    }

    public function test_create_payment_returns_a_failed_result_when_no_key_is_available(): void
    {
        $request = new PaymentRequest(
            amount: 10.0,
            currency: 'EUR',
            to: new MerchantAccount([]),
            locale: 'it-IT',
            purchaseCountry: 'IT',
        );

        $result = $this->gateway(null)->createPayment($request);

        $this->assertFalse($result->success);
    }

    public function test_refund_payment_returns_false_on_an_invalid_key_instead_of_throwing(): void
    {
        $this->assertFalse($this->gateway()->refundPayment('pi_fake', ['access_token' => 'sk_bad_key'], 5.0));
    }

    public function test_deactivate_checkout_returns_false_on_an_invalid_session(): void
    {
        $this->assertFalse($this->gateway()->deactivateCheckout('cs_fake', ['access_token' => 'sk_bad_key']));
    }

    public function test_refresh_credentials_is_a_noop_for_standard_connect_oauth(): void
    {
        $this->assertSame([], $this->gateway()->refreshCredentials('anything'));
    }
}
