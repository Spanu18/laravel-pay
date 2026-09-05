<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Support;

use LaravelPay\LaravelPay\Contracts\CheckoutUrls;
use LaravelPay\LaravelPay\DTO\PaymentRequest;

/**
 * Default CheckoutUrls implementation. Generates checkout redirect URLs
 * relative to the application's configured APP_URL. Consumers that need
 * custom URL schemes should bind their own CheckoutUrls implementation
 * in their application's service provider.
 */
class DefaultCheckoutUrls implements CheckoutUrls
{
    public function success(PaymentRequest $request, array $extra = []): string
    {
        $url = url('/payment/success');
        $params = array_merge(['reference' => $request->reference], $extra);

        return $url.'?'.http_build_query($params);
    }

    public function cancel(): string
    {
        return url('/payment/cancel');
    }

    public function path(string $path): string
    {
        return url(ltrim($path, '/'));
    }
}
