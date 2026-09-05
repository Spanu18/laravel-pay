<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Contracts;

use LaravelPay\LaravelPay\DTO\PaymentRequest;

interface SupportsDeferredCapture
{
    public function createOrder(PaymentRequest $request, string $authorizationToken): ?string;

    public function captureOrder(string $providerOrderId, array $credentials, float $amount): bool;
}
