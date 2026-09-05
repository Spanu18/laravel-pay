<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Gateways;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use LaravelPay\LaravelPay\Contracts\CheckoutUrls;
use LaravelPay\LaravelPay\Contracts\PaymentGatewayInterface;
use LaravelPay\LaravelPay\DTO\CheckoutResult;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\DTO\PaymentResult;
use LaravelPay\LaravelPay\DTO\TransactionResult;
use Psr\Log\LoggerInterface;
use Throwable;

class Coinbase implements PaymentGatewayInterface
{
    protected const API_BASE = 'https://api.commerce.coinbase.com';

    public function __construct(
        protected string $apiVersion,
        protected LoggerInterface $logger,
        protected CheckoutUrls $checkoutUrls,
    ) {}

    protected function http(string $apiKey)
    {
        return Http::withHeaders([
            'X-CC-Api-Key' => $apiKey,
            'X-CC-Version' => $this->apiVersion,
            'Content-Type' => 'application/json',
        ])->timeout(15);
    }

    public function createCredentials(string $code): array
    {
        // code format: "api_key|webhook_secret"
        [$apiKey, $webhookSecret] = array_pad(explode('|', $code, 2), 2, '');

        return [
            // Matched to the legacy Coinbase gateway's stored value so re-linking an
            // already-linked restaurant updates the existing config instead of
            // creating a duplicate.
            'merchant_id' => '',
            'email' => '',
            'credentials' => ['api_key' => $apiKey, 'webhook_secret' => $webhookSecret],
        ];
    }

    public function createPayment(PaymentRequest $request): PaymentResult
    {
        try {
            $credentials = $request->to->credentials;

            $response = $this->http($credentials['api_key'] ?? '')->post(self::API_BASE.'/charges', [
                'name' => 'Order '.($request->orderRef ?? $request->reference),
                'description' => 'Order '.($request->orderRef ?? $request->reference),
                'pricing_type' => 'fixed_price',
                'local_price' => [
                    'amount' => number_format($request->amount, 2, '.', ''),
                    'currency' => strtoupper($request->currency),
                ],
                'metadata' => [
                    'order_ref' => (string) ($request->orderRef ?? ''),
                    'cart_hash' => $request->reference,
                ],
                'redirect_url' => $this->checkoutUrls->success($request),
                'cancel_url' => $this->checkoutUrls->cancel(),
            ]);

            if (! $response->successful()) {
                $this->logger->error('Coinbase createPayment failed', ['body' => $response->json()]);

                return new PaymentResult(false, null, null, 'failed', null);
            }

            $data = $response->json('data', []);

            return new PaymentResult(
                success: true,
                clientSecret: Arr::get($data, 'hosted_url'),
                transactionId: Arr::get($data, 'code'),
                status: $this->latestStatus($data),
                reference: $request->reference,
            );
        } catch (Throwable $e) {
            $this->logger->error('Coinbase createPayment error: '.$e->getMessage(), ['exception' => $e]);

            return new PaymentResult(false, null, null, 'failed', null);
        }
    }

    public function refundPayment(string $transactionId, array $credentials, ?float $amount = null): bool
    {
        // Coinbase Commerce only refunds UNRESOLVED charges (over/under payments) —
        // it cannot refund a normally completed charge; those are returned manually.
        try {
            $response = $this->http($credentials['api_key'] ?? '')->post(self::API_BASE.'/charges/'.$transactionId.'/refund');

            if (! $response->successful()) {
                $this->logger->warning('Coinbase refund not applicable', [
                    'transaction_id' => $transactionId,
                    'body' => $response->json(),
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->logger->error('Coinbase refundPayment error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function deactivateCheckout(string $transactionId, array $credentials): bool
    {
        try {
            $response = $this->http($credentials['api_key'] ?? '')->post(self::API_BASE.'/charges/'.$transactionId.'/cancel');

            return $response->successful();
        } catch (Throwable $e) {
            $this->logger->error('Coinbase deactivateCheckout error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function getCheckout(string $checkoutId, array $credentials): ?CheckoutResult
    {
        try {
            $response = $this->http($credentials['api_key'] ?? '')->get(self::API_BASE.'/charges/'.$checkoutId);

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json('data', []);

            $transactions = [];
            foreach (Arr::get($data, 'payments', []) as $p) {
                $transactions[] = new TransactionResult(
                    providerId: (string) (Arr::get($p, 'transaction_id') ?? Arr::get($p, 'network', '')),
                    status: strtolower((string) Arr::get($p, 'status', '')),
                    currency: strtoupper((string) Arr::get($p, 'value.local.currency', '')),
                    amount: (float) Arr::get($p, 'value.local.amount', 0),
                    timestamp: (string) Arr::get($p, 'detected_at', ''),
                    raw: $p,
                );
            }

            return new CheckoutResult(
                providerId: (string) (Arr::get($data, 'code') ?? $checkoutId),
                status: $this->latestStatus($data),
                currency: strtoupper((string) Arr::get($data, 'pricing.local.currency', 'EUR')),
                amount: (float) Arr::get($data, 'pricing.local.amount', 0),
                timestamp: (string) Arr::get($data, 'created_at', ''),
                transactions: $transactions,
                response: $data,
            );
        } catch (Throwable $e) {
            $this->logger->error('Coinbase getCheckout error: '.$e->getMessage(), ['exception' => $e]);

            return null;
        }
    }

    public function refreshCredentials(string $refreshToken): array
    {
        return []; // static api_key/webhook_secret credentials — nothing to refresh.
    }

    /**
     * Normalize the latest Coinbase timeline status to our internal vocabulary.
     * COMPLETED/RESOLVED are treated as "completed" (paid) by ConfirmPayment.
     */
    protected function latestStatus(array $charge): string
    {
        $timeline = Arr::get($charge, 'timeline', []);
        $raw = ! empty($timeline)
            ? strtoupper((string) Arr::get(end($timeline), 'status', 'NEW'))
            : 'NEW';

        return match ($raw) {
            'COMPLETED', 'RESOLVED' => 'completed',
            'PENDING' => 'pending',
            'EXPIRED' => 'expired',
            'CANCELED' => 'canceled',
            'UNRESOLVED' => 'unresolved',
            default => 'created',
        };
    }
}
