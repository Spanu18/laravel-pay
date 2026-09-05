<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Gateways;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use LaravelPay\LaravelPay\Contracts\PaymentGatewayInterface;
use LaravelPay\LaravelPay\DTO\CheckoutResult;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\DTO\PaymentResult;
use LaravelPay\LaravelPay\DTO\TransactionResult;
use Psr\Log\LoggerInterface;
use Throwable;

class Axerve implements PaymentGatewayInterface
{
    protected const LIVE_BASE = 'https://ecomms2s.sella.it/api/v1';

    protected const SANDBOX_BASE = 'https://sandbox.gestpay.net/api/v1';

    public function __construct(
        protected bool $sandbox,
        protected LoggerInterface $logger,
    ) {}

    protected function baseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_BASE : self::LIVE_BASE;
    }

    protected function http(string $apiKey)
    {
        return Http::withHeaders([
            'Authorization' => 'apikey '.$apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(15);
    }

    public function createCredentials(string $code): array
    {
        // code format: "shop_login|api_key"
        $parts = explode('|', $code, 2);

        return [
            'merchant_id' => $parts[0],
            'email' => '',
            'credentials' => [
                'shop_login' => $parts[0],
                'api_key' => $parts[1] ?? '',
            ],
        ];
    }

    public function createPayment(PaymentRequest $request): PaymentResult
    {
        try {
            $credentials = $request->to->credentials;
            $shopTransId = 'ref'.substr($request->reference, 0, 16);

            $response = $this->http($credentials['api_key'] ?? '')->post($this->baseUrl().'/payment/create', [
                'shopLogin' => $credentials['shop_login'] ?? '',
                'amount' => number_format($request->amount, 2, '.', ''),
                'currency' => strtoupper($request->currency),
                'shopTransactionId' => $shopTransId,
                'buyerName' => $request->from?->name ?? '',
                'buyerEmail' => $request->from?->email ?? '',
                'customInfo' => 'order_ref='.($request->orderRef ?? '').'&cart_hash='.$request->reference,
                'requestToken' => 'MASKEDPAN',
            ]);

            if (! $response->successful()) {
                $this->logger->error('Axerve createPayment HTTP error', ['body' => $response->json()]);

                return new PaymentResult(false, null, null, 'failed', null);
            }

            $body = $response->json();

            if (Arr::get($body, 'error.code') !== '0') {
                $this->logger->error('Axerve createPayment API error', ['body' => $body]);

                return new PaymentResult(false, null, null, 'failed', null);
            }

            return new PaymentResult(
                success: true,
                clientSecret: Arr::get($body, 'payload.userRedirect.href'),
                transactionId: Arr::get($body, 'payload.bankTransactionID', $shopTransId),
                status: 'created',
                reference: $request->reference,
            );
        } catch (Throwable $e) {
            $this->logger->error('Axerve createPayment error: '.$e->getMessage(), ['exception' => $e]);

            return new PaymentResult(false, null, null, 'failed', null);
        }
    }

    public function refundPayment(string $transactionId, array $credentials, ?float $amount = null): bool
    {
        try {
            // The interface doesn't carry currency; hardcoded per Global Constraints.
            $payload = [
                'shopLogin' => $credentials['shop_login'] ?? '',
                'bankTransactionId' => $transactionId,
                'currency' => 'EUR',
            ];

            if ($amount !== null) {
                $payload['amount'] = number_format($amount, 2, '.', '');
            }

            $response = $this->http($credentials['api_key'] ?? '')->post($this->baseUrl().'/payment/refund', $payload);

            return $response->successful() && Arr::get($response->json(), 'error.code') === '0';
        } catch (Throwable $e) {
            $this->logger->error('Axerve refundPayment error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function deactivateCheckout(string $transactionId, array $credentials): bool
    {
        try {
            $response = $this->http($credentials['api_key'] ?? '')->post($this->baseUrl().'/payment/delete', [
                'shopLogin' => $credentials['shop_login'] ?? '',
                'bankTransactionId' => $transactionId,
            ]);

            return $response->successful() && Arr::get($response->json(), 'error.code') === '0';
        } catch (Throwable $e) {
            $this->logger->error('Axerve deactivateCheckout error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function getCheckout(string $checkoutId, array $credentials): ?CheckoutResult
    {
        try {
            $response = $this->http($credentials['api_key'] ?? '')->post($this->baseUrl().'/payment/detail', [
                'shopLogin' => $credentials['shop_login'] ?? '',
                'bankTransactionId' => $checkoutId,
            ]);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->json();

            if (Arr::get($body, 'error.code') !== '0') {
                return null;
            }

            $payload = Arr::get($body, 'payload', []);

            $transactions = [];

            if (! empty($payload['transactionResult'])) {
                $transactions[] = new TransactionResult(
                    providerId: (string) Arr::get($payload, 'bankTransactionID', $checkoutId),
                    status: strtolower(Arr::get($payload, 'transactionResult', '')),
                    currency: strtoupper(Arr::get($payload, 'currency', 'EUR')),
                    amount: (float) Arr::get($payload, 'amount', 0),
                    timestamp: Arr::get($payload, 'transactionDate', ''),
                    raw: $payload,
                );
            }

            return new CheckoutResult(
                providerId: $checkoutId,
                status: strtolower(Arr::get($payload, 'transactionResult', 'unknown')),
                currency: strtoupper(Arr::get($payload, 'currency', 'EUR')),
                amount: (float) Arr::get($payload, 'amount', 0),
                timestamp: Arr::get($payload, 'transactionDate', ''),
                transactions: $transactions,
                response: $body,
            );
        } catch (Throwable $e) {
            $this->logger->error('Axerve getCheckout error: '.$e->getMessage(), ['exception' => $e]);

            return null;
        }
    }

    public function refreshCredentials(string $refreshToken): array
    {
        return []; // static shop_login/api_key credentials — nothing to refresh.
    }
}
