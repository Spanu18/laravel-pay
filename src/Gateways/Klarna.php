<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Gateways;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use LaravelPay\LaravelPay\Contracts\CheckoutUrls;
use LaravelPay\LaravelPay\Contracts\PaymentGatewayInterface;
use LaravelPay\LaravelPay\Contracts\SupportsDeferredCapture;
use LaravelPay\LaravelPay\DTO\CheckoutResult;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\DTO\PaymentResult;
use LaravelPay\LaravelPay\DTO\TransactionResult;
use Psr\Log\LoggerInterface;
use Throwable;

class Klarna implements PaymentGatewayInterface, SupportsDeferredCapture
{
    public function __construct(
        protected bool $playground,
        protected string $defaultRegion,
        protected LoggerInterface $logger,
        protected CheckoutUrls $checkoutUrls,
    ) {}

    protected function baseUrl(string $region): string
    {
        $region = strtolower($region ?: $this->defaultRegion);
        $host = $this->playground
            ? match ($region) {
                'na' => 'api-na.playground.klarna.com',
                'oc' => 'api-oc.playground.klarna.com',
                default => 'api.playground.klarna.com',
            }
        : match ($region) {
            'na' => 'api-na.klarna.com',
            'oc' => 'api-oc.klarna.com',
            default => 'api.klarna.com',
        };

        return 'https://'.$host;
    }

    protected function http(array $credentials)
    {
        return Http::withBasicAuth($credentials['username'] ?? '', $credentials['password'] ?? '')
            ->withHeaders(['Content-Type' => 'application/json'])
            ->timeout(15);
    }

    public function createCredentials(string $code): array
    {
        [$username, $password, $region] = array_pad(explode('|', $code, 3), 3, '');

        return [
            'merchant_id' => $username,
            'email' => null,
            'credentials' => [
                'username' => $username,
                'password' => $password,
                'region' => $region !== '' ? strtolower($region) : 'eu',
            ],
        ];
    }

    public function createPayment(PaymentRequest $request): PaymentResult
    {
        try {
            $credentials = $request->to->credentials;
            $region = $credentials['region'] ?? $this->defaultRegion;
            $currency = strtoupper($request->currency);
            $minor = (int) round($request->amount * 100);

            $sessionResp = $this->http($credentials)->post($this->baseUrl($region).'/payments/v1/sessions', [
                'purchase_country' => strtoupper($request->purchaseCountry),
                'purchase_currency' => $currency,
                'locale' => $request->locale,
                'order_amount' => $minor,
                'order_lines' => [[
                    'name' => 'Order '.($request->orderRef ?? $request->reference),
                    'quantity' => 1,
                    'unit_price' => $minor,
                    'total_amount' => $minor,
                    'tax_rate' => 0,
                    'total_tax_amount' => 0,
                ]],
                'merchant_reference1' => (string) ($request->orderRef ?? $request->reference),
                'merchant_reference2' => $request->reference,
            ]);

            if (! $sessionResp->successful()) {
                $this->logger->error('Klarna create session failed', ['body' => $sessionResp->json()]);

                return new PaymentResult(false, null, null, 'failed', null);
            }

            $sessionId = $sessionResp->json('session_id');

            $successUrl = $this->checkoutUrls->success($request, ['klarna_session' => $sessionId])
                .'&authorization_token={{authorization_token}}';

            $hppResp = $this->http($credentials)->post($this->baseUrl($region).'/hpp/v1/sessions', [
                'payment_session_url' => $this->baseUrl($region).'/payments/v1/sessions/'.$sessionId,
                'merchant_urls' => [
                    'success' => $successUrl,
                    'cancel' => $this->checkoutUrls->cancel(),
                    'back' => $this->checkoutUrls->cancel(),
                    'failure' => $this->checkoutUrls->cancel(),
                    'error' => $this->checkoutUrls->cancel(),
                ],
            ]);

            if (! $hppResp->successful()) {
                $this->logger->error('Klarna create HPP session failed', ['body' => $hppResp->json()]);

                return new PaymentResult(false, null, null, 'failed', null);
            }

            return new PaymentResult(
                success: true,
                clientSecret: $hppResp->json('redirect_url'),
                transactionId: $sessionId,
                status: 'created',
                reference: $request->reference,
            );
        } catch (Throwable $e) {
            $this->logger->error('Klarna createPayment error: '.$e->getMessage(), ['exception' => $e]);

            return new PaymentResult(false, null, null, 'failed', null);
        }
    }

    public function createOrder(PaymentRequest $request, string $authorizationToken): ?string
    {
        try {
            $credentials = $request->to->credentials;
            $region = $credentials['region'] ?? $this->defaultRegion;
            $currency = strtoupper($request->currency);
            $minor = (int) round($request->amount * 100);

            $resp = $this->http($credentials)->post(
                $this->baseUrl($region).'/payments/v1/authorizations/'.$authorizationToken.'/order',
                [
                    'purchase_country' => strtoupper($request->purchaseCountry),
                    'purchase_currency' => $currency,
                    'locale' => $request->locale,
                    'order_amount' => $minor,
                    'order_lines' => [[
                        'name' => 'Order '.($request->orderRef ?? $request->reference),
                        'quantity' => 1,
                        'unit_price' => $minor,
                        'total_amount' => $minor,
                        'tax_rate' => 0,
                        'total_tax_amount' => 0,
                    ]],
                    'merchant_reference1' => (string) ($request->orderRef ?? $request->reference),
                ],
            );

            if (! $resp->successful()) {
                $this->logger->error('Klarna create order failed', ['body' => $resp->json()]);

                return null;
            }

            return $resp->json('order_id');
        } catch (Throwable $e) {
            $this->logger->error('Klarna createOrder error: '.$e->getMessage(), ['exception' => $e]);

            return null;
        }
    }

    public function captureOrder(string $providerOrderId, array $credentials, float $amount): bool
    {
        try {
            $region = $credentials['region'] ?? $this->defaultRegion;

            $resp = $this->http($credentials)->post(
                $this->baseUrl($region).'/ordermanagement/v1/orders/'.$providerOrderId.'/captures',
                ['captured_amount' => (int) round($amount * 100)],
            );

            if (! $resp->successful()) {
                $this->logger->error('Klarna capture failed', ['order_id' => $providerOrderId, 'body' => $resp->json()]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->logger->error('Klarna captureOrder error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function refundPayment(string $transactionId, array $credentials, ?float $amount = null): bool
    {
        try {
            $region = $credentials['region'] ?? $this->defaultRegion;
            $payload = [];

            if ($amount !== null) {
                $payload['refunded_amount'] = (int) round($amount * 100);
            }

            $response = $this->http($credentials)->post(
                $this->baseUrl($region).'/ordermanagement/v1/orders/'.$transactionId.'/refunds',
                $payload,
            );

            return $response->successful();
        } catch (Throwable $e) {
            $this->logger->error('Klarna refundPayment error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function deactivateCheckout(string $transactionId, array $credentials): bool
    {
        try {
            $region = $credentials['region'] ?? $this->defaultRegion;
            $response = $this->http($credentials)->post(
                $this->baseUrl($region).'/ordermanagement/v1/orders/'.$transactionId.'/cancel',
            );

            return $response->successful();
        } catch (Throwable $e) {
            $this->logger->error('Klarna deactivateCheckout error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function getCheckout(string $checkoutId, array $credentials): ?CheckoutResult
    {
        try {
            $region = $credentials['region'] ?? $this->defaultRegion;
            $response = $this->http($credentials)->get(
                $this->baseUrl($region).'/ordermanagement/v1/orders/'.$checkoutId,
            );

            if (! $response->successful()) {
                return null;
            }

            $body = $response->json();

            $transactions = [];
            foreach (Arr::get($body, 'captures', []) as $cap) {
                $transactions[] = new TransactionResult(
                    providerId: (string) Arr::get($cap, 'capture_id', ''),
                    status: 'captured',
                    currency: strtoupper((string) Arr::get($body, 'purchase_currency', 'EUR')),
                    amount: ((float) Arr::get($cap, 'captured_amount', 0)) / 100,
                    timestamp: (string) Arr::get($cap, 'captured_at', ''),
                    raw: $cap,
                );
            }

            return new CheckoutResult(
                providerId: (string) (Arr::get($body, 'order_id') ?? $checkoutId),
                status: strtolower((string) Arr::get($body, 'status', '')),
                currency: strtoupper((string) Arr::get($body, 'purchase_currency', 'EUR')),
                amount: ((float) Arr::get($body, 'order_amount', 0)) / 100,
                timestamp: (string) Arr::get($body, 'created_at', ''),
                transactions: $transactions,
                response: $body,
            );
        } catch (Throwable $e) {
            $this->logger->error('Klarna getCheckout error: '.$e->getMessage(), ['exception' => $e]);

            return null;
        }
    }

    public function refreshCredentials(string $refreshToken): array
    {
        return []; // static username/password credentials — nothing to refresh.
    }
}
