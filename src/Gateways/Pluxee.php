<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Gateways;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LaravelPay\LaravelPay\Contracts\CheckoutUrls;
use LaravelPay\LaravelPay\Contracts\PaymentGatewayInterface;
use LaravelPay\LaravelPay\DTO\CheckoutResult;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\DTO\PaymentResult;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pluxee (ex-Sodexo) meal-voucher gateway (hosted checkout, Italy).
 * createCredentials() accepts "client_id|client_secret|merchant_id".
 */
class Pluxee implements PaymentGatewayInterface
{
    protected const AUTH_BASE = 'https://auth.pluxee.com';

    protected const API_BASE = 'https://api.pluxee.com';

    public function __construct(
        protected LoggerInterface $logger,
        protected CheckoutUrls $checkoutUrls,
    ) {}

    public function createCredentials(string $code): array
    {
        // code format: "client_id|client_secret|merchant_id"
        [$clientId, $clientSecret, $merchantId] = array_pad(explode('|', $code, 3), 3, '');

        return [
            'merchant_id' => $merchantId,
            'email' => '',
            'credentials' => ['client_id' => $clientId, 'client_secret' => $clientSecret, 'merchant_id' => $merchantId],
        ];
    }

    public function createPayment(PaymentRequest $request): PaymentResult
    {
        try {
            $credentials = $request->to->credentials;
            $token = $this->fetchAccessToken($credentials);

            if (! $token) {
                return new PaymentResult(false, null, null, 'failed', null);
            }

            $response = Http::withToken($token)->timeout(15)->post(self::API_BASE.'/v1/payments', [
                'merchant_id' => $credentials['merchant_id'] ?? '',
                'amount' => (int) round($request->amount * 100),
                'currency' => strtoupper($request->currency),
                'order_ref' => (string) ($request->orderRef ?? $request->reference),
                'description' => 'Order '.($request->orderRef ?? $request->reference),
                'success_url' => $this->checkoutUrls->success($request),
                'cancel_url' => $this->checkoutUrls->cancel(),
                'webhook_url' => $this->checkoutUrls->path('/webhooks/pluxee'),
                'metadata' => [
                    'order_ref' => (string) ($request->orderRef ?? ''),
                    'cart_hash' => $request->reference,
                ],
            ]);

            if (! $response->successful()) {
                $this->logger->error('Pluxee createPayment failed', ['body' => $response->json()]);

                return new PaymentResult(false, null, null, 'failed', null);
            }

            $body = $response->json();

            return new PaymentResult(
                success: true,
                clientSecret: Arr::get($body, 'payment_url') ?? Arr::get($body, 'redirect_url'),
                transactionId: Arr::get($body, 'payment_id') ?? Arr::get($body, 'id'),
                status: strtolower(Arr::get($body, 'status', 'pending')),
                reference: $request->reference,
            );
        } catch (Throwable $e) {
            $this->logger->error('Pluxee createPayment error: '.$e->getMessage(), ['exception' => $e]);

            return new PaymentResult(false, null, null, 'failed', null);
        }
    }

    public function refundPayment(string $transactionId, array $credentials, ?float $amount = null): bool
    {
        try {
            $token = $this->fetchAccessToken($credentials);

            if (! $token) {
                return false;
            }

            $payload = ['merchant_id' => $credentials['merchant_id'] ?? ''];

            if ($amount !== null) {
                $payload['amount'] = (int) round($amount * 100);
            }

            $response = Http::withToken($token)->timeout(15)->post(self::API_BASE.'/v1/payments/'.$transactionId.'/refund', $payload);

            return $response->successful();
        } catch (Throwable $e) {
            $this->logger->error('Pluxee refundPayment error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function deactivateCheckout(string $transactionId, array $credentials): bool
    {
        try {
            $token = $this->fetchAccessToken($credentials);

            if (! $token) {
                return false;
            }

            $response = Http::withToken($token)->timeout(15)->post(self::API_BASE.'/v1/payments/'.$transactionId.'/cancel', [
                'merchant_id' => $credentials['merchant_id'] ?? '',
            ]);

            return $response->successful();
        } catch (Throwable $e) {
            $this->logger->error('Pluxee deactivateCheckout error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function getCheckout(string $checkoutId, array $credentials): ?CheckoutResult
    {
        try {
            $token = $this->fetchAccessToken($credentials);

            if (! $token) {
                return null;
            }

            $response = Http::withToken($token)->timeout(15)->get(self::API_BASE.'/v1/payments/'.$checkoutId, [
                'merchant_id' => $credentials['merchant_id'] ?? '',
            ]);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->json();

            return new CheckoutResult(
                providerId: (string) (Arr::get($body, 'payment_id') ?? Arr::get($body, 'id') ?? $checkoutId),
                status: $this->mapStatus((string) Arr::get($body, 'status', '')),
                currency: strtoupper((string) Arr::get($body, 'currency', 'EUR')),
                amount: (float) Arr::get($body, 'amount', 0) / 100,
                timestamp: Arr::get($body, 'created_at'),
                transactions: [],
                response: $body,
            );
        } catch (Throwable $e) {
            $this->logger->error('Pluxee getCheckout error: '.$e->getMessage(), ['exception' => $e]);

            return null;
        }
    }

    public function refreshCredentials(string $refreshToken): array
    {
        return []; // static client_id/client_secret credentials — nothing to refresh.
    }

    protected function fetchAccessToken(array $credentials): ?string
    {
        $clientId = $credentials['client_id'] ?? '';
        $cacheKey = 'pluxee:token:'.hash('sha256', $clientId);

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::asForm()->timeout(15)->post(self::AUTH_BASE.'/oauth/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $credentials['client_secret'] ?? '',
                'scope' => 'payments',
            ]);

            if (! $response->successful()) {
                $this->logger->error('Pluxee token fetch failed', ['body' => $response->json()]);

                return null;
            }

            $token = $response->json('access_token');
            $expires = (int) $response->json('expires_in', 3600);

            if ($token) {
                Cache::put($cacheKey, $token, now()->addSeconds($expires - 60));
            }

            return $token ?: null;
        } catch (Throwable $e) {
            $this->logger->error('Pluxee token fetch error: '.$e->getMessage(), ['exception' => $e]);

            return null;
        }
    }

    protected function mapStatus(string $status): string
    {
        return match (strtolower($status)) {
            'completed', 'paid', 'success', 'approved' => 'paid',
            'pending', 'created', 'initiated' => 'pending',
            'cancelled', 'canceled' => 'canceled',
            'expired' => 'expired',
            'refunded' => 'refunded',
            default => 'created',
        };
    }
}
