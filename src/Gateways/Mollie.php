<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Gateways;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use LaravelPay\LaravelPay\Contracts\CheckoutUrls;
use LaravelPay\LaravelPay\Contracts\PaymentGatewayInterface;
use LaravelPay\LaravelPay\DTO\CheckoutResult;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\DTO\PaymentResult;
use LaravelPay\LaravelPay\DTO\TransactionResult;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class Mollie implements PaymentGatewayInterface
{
    protected const API_BASE = 'https://api.mollie.com/v2';

    protected const TOKEN_URL = 'https://api.mollie.com/oauth2/tokens';

    public function __construct(
        protected string $clientId,
        protected string $clientSecret,
        protected LoggerInterface $logger,
        protected CheckoutUrls $checkoutUrls,
    ) {}

    public function createCredentials(string $code): array
    {
        try {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()->timeout(10)
                ->post(self::TOKEN_URL, [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->checkoutUrls->path('/callback/payments/mollie'),
                ]);

            if (! $response->successful()) {
                $this->logger->error('Mollie createCredentials failed', ['body' => $response->json()]);

                throw new RuntimeException('Mollie token exchange failed');
            }

            $body = $response->json();

            $orgResponse = Http::withToken($body['access_token'])->timeout(10)->get(self::API_BASE.'/organizations/me');

            return [
                'merchant_id' => $orgResponse->json('id', ''),
                'email' => $orgResponse->json('email', ''),
                'credentials' => [
                    'access_token' => $body['access_token'],
                    'refresh_token' => $body['refresh_token'] ?? '',
                    'expires_at' => now()->addSeconds($body['expires_in'] ?? 3600)->toDateTimeString(),
                ],
            ];
        } catch (Throwable $e) {
            $this->logger->error('Mollie createCredentials error: '.$e->getMessage(), ['exception' => $e]);

            throw $e;
        }
    }

    public function refreshCredentials(string $refreshToken): array
    {
        try {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()->timeout(10)
                ->post(self::TOKEN_URL, ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);

            if (! $response->successful()) {
                $this->logger->error('Mollie refreshCredentials failed', ['body' => $response->json()]);

                return [];
            }

            $body = $response->json();

            return [
                'access_token' => $body['access_token'],
                'refresh_token' => $body['refresh_token'] ?? $refreshToken,
                'expires_at' => now()->addSeconds($body['expires_in'] ?? 3600)->toDateTimeString(),
            ];
        } catch (Throwable $e) {
            $this->logger->error('Mollie refreshCredentials error: '.$e->getMessage(), ['exception' => $e]);

            return [];
        }
    }

    protected function postPayment(PaymentRequest $request, string $accessToken): Response
    {
        return Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->timeout(15)
            ->post(self::API_BASE.'/payments', [
                'amount' => ['currency' => strtoupper($request->currency), 'value' => number_format($request->amount, 2, '.', '')],
                'description' => 'Order '.($request->orderRef ?? $request->reference),
                'redirectUrl' => $this->checkoutUrls->success($request),
                'cancelUrl' => $this->checkoutUrls->cancel(),
                'metadata' => [
                    'order_ref' => (string) ($request->orderRef ?? ''),
                    'cart_hash' => $request->reference,
                ],
            ]);
    }

    public function createPayment(PaymentRequest $request): PaymentResult
    {
        try {
            $credentials = $request->to->credentials;
            $response = $this->postPayment($request, $credentials['access_token'] ?? '');

            if ($response->status() === 401) {
                $refreshToken = $credentials['refresh_token'] ?? null;

                if (! $refreshToken) {
                    $this->logger->error('Mollie createPayment failed: unauthorized, no refresh token');

                    return new PaymentResult(false, null, null, 'failed', null);
                }

                $fresh = $this->refreshCredentials($refreshToken);

                if (empty($fresh)) {
                    $this->logger->error('Mollie createPayment failed: token refresh failed');

                    return new PaymentResult(false, null, null, 'failed', null);
                }

                $response = $this->postPayment($request, $fresh['access_token']);

                if (! $response->successful()) {
                    $this->logger->error('Mollie createPayment failed after refresh', ['body' => $response->json()]);

                    return new PaymentResult(false, null, null, 'failed', null);
                }

                $body = $response->json();

                return new PaymentResult(
                    success: true,
                    clientSecret: Arr::get($body, '_links.checkout.href'),
                    transactionId: $body['id'],
                    status: $body['status'] ?? 'open',
                    reference: $request->reference,
                    refreshedCredentials: $fresh,
                );
            }

            if (! $response->successful()) {
                $this->logger->error('Mollie createPayment failed', ['body' => $response->json()]);

                return new PaymentResult(false, null, null, 'failed', null);
            }

            $body = $response->json();

            return new PaymentResult(
                success: true,
                clientSecret: Arr::get($body, '_links.checkout.href'),
                transactionId: $body['id'],
                status: $body['status'] ?? 'open',
                reference: $request->reference,
            );
        } catch (Throwable $e) {
            $this->logger->error('Mollie createPayment error: '.$e->getMessage(), ['exception' => $e]);

            return new PaymentResult(false, null, null, 'failed', null);
        }
    }

    public function refundPayment(string $transactionId, array $credentials, ?float $amount = null): bool
    {
        try {
            $payload = [];

            if ($amount !== null) {
                // The interface doesn't carry currency; hardcoded per Global Constraints.
                $payload['amount'] = ['currency' => 'EUR', 'value' => number_format($amount, 2, '.', '')];
            }

            $response = Http::withToken($credentials['access_token'] ?? '')
                ->withHeaders(['Content-Type' => 'application/json'])
                ->timeout(15)
                ->post(self::API_BASE.'/payments/'.$transactionId.'/refunds', $payload);

            if ($response->status() === 401) {
                // No PaymentResult DTO here to carry a refreshedCredentials field, so
                // the fresh token is used for this call only and not persisted —
                // accepted as a bounded trade-off (see Mollie 401-retry task note).
                $refreshToken = $credentials['refresh_token'] ?? null;

                if (! $refreshToken) {
                    $this->logger->error('Mollie refundPayment failed: unauthorized, no refresh token');

                    return false;
                }

                $fresh = $this->refreshCredentials($refreshToken);

                if (empty($fresh)) {
                    $this->logger->error('Mollie refundPayment failed: token refresh failed');

                    return false;
                }

                $response = Http::withToken($fresh['access_token'])
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->timeout(15)
                    ->post(self::API_BASE.'/payments/'.$transactionId.'/refunds', $payload);
            }

            return $response->successful();
        } catch (Throwable $e) {
            $this->logger->error('Mollie refundPayment error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function deactivateCheckout(string $transactionId, array $credentials): bool
    {
        try {
            $response = Http::withToken($credentials['access_token'] ?? '')
                ->timeout(15)
                ->delete(self::API_BASE.'/payments/'.$transactionId);

            if ($response->status() === 401) {
                // See refundPayment() above: refreshed token used for this call only,
                // not persisted (no DTO to carry it back through this bool-returning method).
                $refreshToken = $credentials['refresh_token'] ?? null;

                if (! $refreshToken) {
                    $this->logger->error('Mollie deactivateCheckout failed: unauthorized, no refresh token');

                    return false;
                }

                $fresh = $this->refreshCredentials($refreshToken);

                if (empty($fresh)) {
                    $this->logger->error('Mollie deactivateCheckout failed: token refresh failed');

                    return false;
                }

                $response = Http::withToken($fresh['access_token'])
                    ->timeout(15)
                    ->delete(self::API_BASE.'/payments/'.$transactionId);
            }

            return $response->successful();
        } catch (Throwable $e) {
            $this->logger->error('Mollie deactivateCheckout error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function getCheckout(string $checkoutId, array $credentials): ?CheckoutResult
    {
        try {
            $response = Http::withToken($credentials['access_token'] ?? '')
                ->timeout(15)
                ->get(self::API_BASE.'/payments/'.$checkoutId);

            if ($response->status() === 401) {
                // See refundPayment() above: refreshed token used for this call only,
                // not persisted (no DTO to carry it back through this nullable-DTO method).
                $refreshToken = $credentials['refresh_token'] ?? null;

                if (! $refreshToken) {
                    $this->logger->error('Mollie getCheckout failed: unauthorized, no refresh token');

                    return null;
                }

                $fresh = $this->refreshCredentials($refreshToken);

                if (empty($fresh)) {
                    $this->logger->error('Mollie getCheckout failed: token refresh failed');

                    return null;
                }

                $response = Http::withToken($fresh['access_token'])
                    ->timeout(15)
                    ->get(self::API_BASE.'/payments/'.$checkoutId);
            }

            if (! $response->successful()) {
                return null;
            }

            $body = $response->json();

            $transactions = [];
            foreach ($body['chargebacks'] ?? [] as $cb) {
                $transactions[] = new TransactionResult(
                    providerId: $cb['id'] ?? '',
                    status: 'chargeback',
                    currency: strtoupper($cb['amount']['currency'] ?? ''),
                    amount: (float) ($cb['amount']['value'] ?? 0),
                    timestamp: $cb['createdAt'] ?? '',
                    raw: $cb,
                );
            }

            return new CheckoutResult(
                providerId: $checkoutId,
                status: $body['status'] ?? '',
                currency: strtoupper(Arr::get($body, 'amount.currency', 'EUR')),
                amount: (float) Arr::get($body, 'amount.value', 0),
                timestamp: $body['createdAt'] ?? '',
                transactions: $transactions,
                response: $body,
            );
        } catch (Throwable $e) {
            $this->logger->error('Mollie getCheckout error: '.$e->getMessage(), ['exception' => $e]);

            return null;
        }
    }
}
