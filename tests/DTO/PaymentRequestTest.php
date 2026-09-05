<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Tests\DTO;

use LaravelPay\LaravelPay\DTO\MerchantAccount;
use LaravelPay\LaravelPay\DTO\PayerContact;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\Tests\TestCase;

class PaymentRequestTest extends TestCase
{
    private function merchant(): MerchantAccount
    {
        return new MerchantAccount(['api_key' => 'sk_test']);
    }

    public function test_it_generates_a_uuid_reference_when_none_is_passed(): void
    {
        $request = new PaymentRequest(
            amount: 12.50,
            currency: 'EUR',
            to: $this->merchant(),
            locale: 'it-IT',
            purchaseCountry: 'IT',
        );

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $request->reference,
        );
    }

    public function test_it_uses_the_caller_supplied_reference_when_given(): void
    {
        $request = new PaymentRequest(
            amount: 12.50,
            currency: 'EUR',
            to: $this->merchant(),
            locale: 'it-IT',
            purchaseCountry: 'IT',
            reference: 'cart-hash-abc123',
        );

        $this->assertSame('cart-hash-abc123', $request->reference);
    }

    public function test_from_and_order_ref_default_to_null(): void
    {
        $request = new PaymentRequest(
            amount: 12.50,
            currency: 'EUR',
            to: $this->merchant(),
            locale: 'it-IT',
            purchaseCountry: 'IT',
        );

        $this->assertNull($request->from);
        $this->assertNull($request->orderRef);
    }

    public function test_it_carries_an_optional_payer_contact(): void
    {
        $from = new PayerContact(name: 'Mario', surname: 'Rossi', email: 'mario@example.com');

        $request = new PaymentRequest(
            amount: 12.50,
            currency: 'EUR',
            to: $this->merchant(),
            locale: 'it-IT',
            purchaseCountry: 'IT',
            from: $from,
            orderRef: 42,
        );

        $this->assertSame('mario@example.com', $request->from->email);
        $this->assertSame(42, $request->orderRef);
    }
}
