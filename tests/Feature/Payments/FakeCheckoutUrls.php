<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Tests\Feature\Payments;

use LaravelPay\LaravelPay\Contracts\CheckoutUrls;
use LaravelPay\LaravelPay\DTO\PaymentRequest;

class FakeCheckoutUrls implements CheckoutUrls
{
    public function success(PaymentRequest $request, array $extra = []): string
    {
        return 'https://app.test/checkout/success?'.http_build_query($extra);
    }

    public function cancel(): string
    {
        return 'https://app.test/checkout/cancel';
    }

    public function path(string $path): string
    {
        return 'https://app.test/'.ltrim($path, '/');
    }
}
