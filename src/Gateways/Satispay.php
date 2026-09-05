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
use Psr\Log\LoggerInterface;
use Throwable;

class Satispay implements PaymentGatewayInterface
{
    public function __construct(
        protected bool $sandbox,
        protected LoggerInterface $logger,
        protected CheckoutUrls $checkoutUrls,
    ) {}

    protected function baseUrl(): string
    {
        return $this->sandbox ? 'https://staging.online.satispay.com' : 'https://online.satispay.com';
    }

    protected function http(string $token)
    {
        return Http::withToken($token)->withHeaders(['Content-Type' => 'application/json'])->timeout(15);
    }

    public function createCredentials(string $code): array
    {
        // $code is the bearer token from the Satispay business dashboard, used as-is.
        return [
            // Matched to the legacy Satispay gateway's stored value so re-linking an
            // already-linked restaurant updates the existing config instead of
            // creating a duplicate.
            'merchant_id' => '',
            'email' => '',
            'credentials' => ['token' => $code],
        ];
    }

    public function createPayment(PaymentRequest $request): PaymentResult
    {
        try {
            $token = $request->to->credentials['token'] ?? '';

            $response = $this->http($token)->post($this->baseUrl().'/v1/payments', [
                'flow' => 'REDIRECT_TO_URL',
                'amount_unit' => (int) round($request->amount * 100),
                'currency' => strtoupper($request->currency),
                'description' => 'Order '.($request->orderRef ?? $request->reference),
                'callback_url' => $this->checkoutUrls->path('/checkout/satispay/callback').'?order_ref='.($request->orderRef ?? ''),
                'redirect_url' => $this->checkoutUrls->success($request),
                'metadata' => [
                    'order_ref' => (string) ($request->orderRef ?? ''),
                    'cart_hash' => $request->reference,
                ],
            ]);

            if (! $response->successful()) {
                $this->logger->error('Satispay createPayment failed', ['body' => $response->json()]);

                return new PaymentResult(false, null, null, 'failed', null);
            }

            $body = $response->json();
            $redirectUrl = Arr::get($body, 'redirect_url') ?? Arr::get($body, 'deeplink');

            return new PaymentResult(
                success: true,
                clientSecret: $redirectUrl,
                transactionId: $body['id'],
                status: strtolower($body['status'] ?? 'pending'),
                reference: $request->reference,
            );
        } catch (Throwable $e) {
            $this->logger->error('Satispay createPayment error: '.$e->getMessage(), ['exception' => $e]);

            return new PaymentResult(false, null, null, 'failed', null);
        }
    }

    public function refundPayment(string $transactionId, array $credentials, ?float $amount = null): bool
    {
        try {
            $payload = ['action' => 'REFUND'];

            if ($amount !== null) {
                $payload['amount_unit'] = (int) round($amount * 100);
            }

            $response = $this->http($credentials['token'] ?? '')->put($this->baseUrl().'/v1/payments/'.$transactionId, $payload);

            return $response->successful();
        } catch (Throwable $e) {
            $this->logger->error('Satispay refundPayment error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function deactivateCheckout(string $transactionId, array $credentials): bool
    {
        try {
            $response = $this->http($credentials['token'] ?? '')->put(
                $this->baseUrl().'/v1/payments/'.$transactionId,
                ['action' => 'CANCEL'],
            );

            return $response->successful();
        } catch (Throwable $e) {
            $this->logger->error('Satispay deactivateCheckout error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function getCheckout(string $checkoutId, array $credentials): ?CheckoutResult
    {
        try {
            $response = $this->http($credentials['token'] ?? '')->get($this->baseUrl().'/v1/payments/'.$checkoutId);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->json();

            return new CheckoutResult(
                providerId: $checkoutId,
                status: strtolower($body['status'] ?? ''),
                currency: strtoupper($body['currency'] ?? 'EUR'),
                amount: ($body['amount_unit'] ?? 0) / 100,
                timestamp: $body['insert_date'] ?? '',
                transactions: [],
                response: $body,
            );
        } catch (Throwable $e) {
            $this->logger->error('Satispay getCheckout error: '.$e->getMessage(), ['exception' => $e]);

            return null;
        }
    }

    public function refreshCredentials(string $refreshToken): array
    {
        return []; // static long-lived merchant token — nothing to refresh.
    }
}
