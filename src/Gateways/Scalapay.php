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

/**
 * Scalapay gateway — BNPL, hosted-checkout flow. createPayment() returns a
 * stable `token` that IS the provider order id from the start (no separate
 * authorization-token exchange step, unlike Klarna) — captureOrder() pays the
 * merchant once the customer completes Scalapay's hosted checkout.
 */
class Scalapay implements PaymentGatewayInterface
{
    protected const LIVE_BASE = 'https://api.scalapay.com';

    protected const SANDBOX_BASE = 'https://integration.api.scalapay.com';

    public function __construct(
        protected bool $sandbox,
        protected LoggerInterface $logger,
        protected CheckoutUrls $checkoutUrls,
    ) {}

    protected function baseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_BASE : self::LIVE_BASE;
    }

    protected function http(string $apiKey)
    {
        return Http::withToken($apiKey)->withHeaders(['Content-Type' => 'application/json'])->timeout(15);
    }

    public function createCredentials(string $code): array
    {
        // $code is the raw api_key (Bearer secret), used as-is.
        return [
            // Matched to the legacy Scalapay gateway's stored value so re-linking an
            // already-linked restaurant updates the existing config instead of
            // creating a duplicate.
            'merchant_id' => '',
            'email' => '',
            'credentials' => ['api_key' => $code],
        ];
    }

    public function createPayment(PaymentRequest $request): PaymentResult
    {
        try {
            $credentials = $request->to->credentials;
            $currency = strtoupper($request->currency);
            $value = number_format($request->amount, 2, '.', '');

            $response = $this->http($credentials['api_key'] ?? '')->post($this->baseUrl().'/v2/orders', [
                'totalAmount' => ['amount' => $value, 'currency' => $currency],
                'consumer' => [
                    'givenNames' => $request->from?->name ?? '',
                    'surname' => $request->from?->surname ?? '',
                    'email' => $request->from?->email ?? '',
                ],
                'items' => [[
                    'name' => 'Order '.($request->orderRef ?? $request->reference),
                    'quantity' => 1,
                    'price' => ['amount' => $value, 'currency' => $currency],
                ]],
                'merchant' => [
                    'redirectConfirmUrl' => $this->checkoutUrls->success($request),
                    'redirectCancelUrl' => $this->checkoutUrls->cancel(),
                ],
                'merchantReference' => (string) ($request->orderRef ?? $request->reference),
            ]);

            if (! $response->successful()) {
                $this->logger->error('Scalapay createPayment failed', ['body' => $response->json()]);

                return new PaymentResult(false, null, null, 'failed', null);
            }

            $body = $response->json();

            return new PaymentResult(
                success: true,
                clientSecret: Arr::get($body, 'checkoutUrl'),
                transactionId: Arr::get($body, 'token'),
                status: strtolower((string) Arr::get($body, 'status', 'created')),
                reference: $request->reference,
            );
        } catch (Throwable $e) {
            $this->logger->error('Scalapay createPayment error: '.$e->getMessage(), ['exception' => $e]);

            return new PaymentResult(false, null, null, 'failed', null);
        }
    }

    /**
     * Capture the payment for an authorized order (extra method, not part of
     * PaymentGatewayInterface — see this task's note on why Scalapay doesn't
     * implement SupportsDeferredCapture). $amount is accepted for interface-shape
     * consistency with other capture-style calls but unused: Scalapay always
     * captures the order's full authorized amount, matching legacy behavior.
     */
    public function captureOrder(string $providerOrderId, array $credentials, float $amount): bool
    {
        try {
            $response = $this->http($credentials['api_key'] ?? '')->post($this->baseUrl().'/v2/payments/capture', [
                'token' => $providerOrderId,
            ]);

            if (! $response->successful()) {
                $this->logger->error('Scalapay capture failed', ['token' => $providerOrderId, 'body' => $response->json()]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->logger->error('Scalapay captureOrder error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function refundPayment(string $transactionId, array $credentials, ?float $amount = null): bool
    {
        try {
            // The interface doesn't carry currency; hardcoded per Global Constraints.
            $payload = [];

            if ($amount !== null) {
                $payload['refundAmount'] = ['amount' => number_format($amount, 2, '.', ''), 'currency' => 'EUR'];
            }

            $response = $this->http($credentials['api_key'] ?? '')->post($this->baseUrl().'/v2/payments/'.$transactionId.'/refund', $payload);

            return $response->successful();
        } catch (Throwable $e) {
            $this->logger->error('Scalapay refundPayment error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function deactivateCheckout(string $transactionId, array $credentials): bool
    {
        try {
            $response = $this->http($credentials['api_key'] ?? '')->post($this->baseUrl().'/v2/payments/'.$transactionId.'/void');

            return $response->successful();
        } catch (Throwable $e) {
            $this->logger->error('Scalapay deactivateCheckout error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function getCheckout(string $checkoutId, array $credentials): ?CheckoutResult
    {
        try {
            $response = $this->http($credentials['api_key'] ?? '')->get($this->baseUrl().'/v2/payments/'.$checkoutId);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->json();

            $currency = strtoupper((string) Arr::get($body, 'totalAmount.currency', 'EUR'));
            $amount = (float) Arr::get($body, 'totalAmount.amount', 0);
            $status = strtolower((string) Arr::get($body, 'status', ''));

            $transactions = [];
            foreach (Arr::get($body, 'payments', []) as $p) {
                $transactions[] = new TransactionResult(
                    providerId: (string) (Arr::get($p, 'id') ?? Arr::get($p, 'token', '')),
                    status: strtolower((string) Arr::get($p, 'status', $status)),
                    currency: $currency,
                    amount: (float) Arr::get($p, 'amount.amount', $amount),
                    timestamp: (string) Arr::get($p, 'date', ''),
                    raw: $p,
                );
            }

            return new CheckoutResult(
                providerId: (string) (Arr::get($body, 'token') ?? $checkoutId),
                status: $status,
                currency: $currency,
                amount: $amount,
                timestamp: (string) Arr::get($body, 'orderDate', ''),
                transactions: $transactions,
                response: $body,
            );
        } catch (Throwable $e) {
            $this->logger->error('Scalapay getCheckout error: '.$e->getMessage(), ['exception' => $e]);

            return null;
        }
    }

    public function refreshCredentials(string $refreshToken): array
    {
        return []; // static api_key credentials — nothing to refresh.
    }
}
