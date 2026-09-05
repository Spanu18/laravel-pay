<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Contracts;

use LaravelPay\LaravelPay\DTO\PaymentRequest;

interface CheckoutUrls
{
    public function success(PaymentRequest $request, array $extra = []): string;

    public function cancel(): string;

    /**
     * An absolute app URL for a fixed path that isn't part of the checkout
     * redirect flow itself — webhook targets, OAuth redirect URIs. Unlike
     * success()/cancel(), not tied to a specific PaymentRequest.
     */
    public function path(string $path): string;
}
