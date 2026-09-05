<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Gateways;

use Illuminate\Support\Facades\Http;
use LaravelPay\LaravelPay\Contracts\CheckoutUrls;
use LaravelPay\LaravelPay\Contracts\PaymentGatewayInterface;
use LaravelPay\LaravelPay\DTO\CheckoutResult;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\DTO\PaymentResult;
use Psr\Log\LoggerInterface;
use Throwable;

class Adyen implements PaymentGatewayInterface
{
    public function __construct(
        protected bool $live,
        protected LoggerInterface $logger,
        protected CheckoutUrls $checkoutUrls,
    ) {}

    protected function baseUrl(): string
    {
        return $this->live
            ? 'https://checkout-live.adyen.com/checkout'
            : 'https://checkout-test.adyen.com';
    }

    protected function http(string $apiKey)
    {
        return Http::withHeaders([
            'x-api-key' => $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(15);
    }

    public function createCredentials(string $code): array
    {
        // code format: "api_key|merchant_account|client_key"
        $parts = explode('|', $code, 3);
        $merchantAccount = $parts[1] ?? '';

        return [
            'merchant_id' => $merchantAccount,
            'email' => '',
            'credentials' => [
                'api_key' => $parts[0],
                'merchant_account' => $merchantAccount,
                'client_key' => $parts[2] ?? '',
            ],
        ];
    }

    public function createPayment(PaymentRequest $request): PaymentResult
    {
        try {
            $credentials = $request->to->credentials;
            $merchantAccount = $credentials['merchant_account'] ?? '';

            $response = $this->http($credentials['api_key'] ?? '')->post($this->baseUrl().'/v71/paymentLinks', [
                'amount' => [
                    'currency' => strtoupper($request->currency),
                    'value' => (int) round($request->amount * 100),
                ],
                'merchantAccount' => $merchantAccount,
                'reference' => 'order-'.($request->orderRef ?? $request->reference).'-'.$request->reference,
                'returnUrl' => $this->checkoutUrls->success($request),
                'metadata' => [
                    'order_ref' => (string) ($request->orderRef ?? ''),
                    'cart_hash' => $request->reference,
                ],
            ]);

            if (! $response->successful()) {
                $this->logger->error('Adyen createPayment failed', ['body' => $response->json()]);

                return new PaymentResult(false, null, null, 'failed', null);
            }

            $body = $response->json();

            return new PaymentResult(
                success: true,
                clientSecret: $body['url'],
                transactionId: $body['id'],
                status: $body['status'] ?? 'active',
                reference: $request->reference,
            );
        } catch (Throwable $e) {
            $this->logger->error('Adyen createPayment error: '.$e->getMessage(), ['exception' => $e]);

            return new PaymentResult(false, null, null, 'failed', null);
        }
    }

    public function refundPayment(string $transactionId, array $credentials, ?float $amount = null): bool
    {
        try {
            // The interface doesn't carry currency; Adyen's refund endpoint requires
            // one, so this hardcodes 'EUR' — see Global Constraints.
            $payload = [
                'merchantAccount' => $credentials['merchant_account'] ?? '',
                'reference' => 'refund-'.$transactionId,
            ];

            if ($amount !== null) {
                $payload['amount'] = ['currency' => 'EUR', 'value' => (int) round($amount * 100)];
            }

            $response = $this->http($credentials['api_key'] ?? '')->post(
                $this->baseUrl().'/v68/payments/'.$transactionId.'/refunds',
                $payload,
            );

            return $response->successful();
        } catch (Throwable $e) {
            $this->logger->error('Adyen refundPayment error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function deactivateCheckout(string $transactionId, array $credentials): bool
    {
        try {
            $response = $this->http($credentials['api_key'] ?? '')->patch(
                $this->baseUrl().'/v71/paymentLinks/'.$transactionId,
                ['status' => 'expired'],
            );

            return $response->successful();
        } catch (Throwable $e) {
            $this->logger->error('Adyen deactivateCheckout error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function getCheckout(string $checkoutId, array $credentials): ?CheckoutResult
    {
        try {
            $response = $this->http($credentials['api_key'] ?? '')->get($this->baseUrl().'/v71/paymentLinks/'.$checkoutId);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->json();

            return new CheckoutResult(
                providerId: $checkoutId,
                status: $body['status'] ?? '',
                currency: strtoupper($body['amount']['currency'] ?? ''),
                amount: ($body['amount']['value'] ?? 0) / 100,
                timestamp: $body['expiresAt'] ?? '',
                transactions: [],
                response: $body,
            );
        } catch (Throwable $e) {
            $this->logger->error('Adyen getCheckout error: '.$e->getMessage(), ['exception' => $e]);

            return null;
        }
    }

    public function refreshCredentials(string $refreshToken): array
    {
        return []; // static api_key/merchant_account/client_key credentials — nothing to refresh.
    }
}
