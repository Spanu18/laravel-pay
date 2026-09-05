<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Contracts;

use LaravelPay\LaravelPay\DTO\CheckoutResult;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\DTO\PaymentResult;

interface PaymentGatewayInterface
{
    public function createPayment(PaymentRequest $request): PaymentResult;

    public function refundPayment(string $transactionId, array $credentials, ?float $amount = null): bool;

    public function deactivateCheckout(string $transactionId, array $credentials): bool;

    public function getCheckout(string $checkoutId, array $credentials): ?CheckoutResult;

    /**
     * Exchanges an onboarding code for a credential bundle. Returns the data
     * to store — does NOT persist anything itself.
     *
     * @return array{merchant_id?: string|null, email?: string|null, credentials: array}
     */
    public function createCredentials(string $code): array;

    /**
     * Mints a fresh credential bundle from a refresh token. Returns the
     * fields to merge into stored credentials — does NOT persist them.
     * Gateways with static (non-OAuth) credentials return [].
     */
    public function refreshCredentials(string $refreshToken): array;
}
