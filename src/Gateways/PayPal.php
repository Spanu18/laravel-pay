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

class PayPal implements PaymentGatewayInterface
{
    public function __construct(
        protected bool $sandbox,
        protected LoggerInterface $logger,
        protected CheckoutUrls $checkoutUrls,
    ) {}

    protected function baseUrl(): string
    {
        return $this->sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }

    protected function bearerToken(string $clientId, string $secret): string
    {
        $response = Http::withBasicAuth($clientId, $secret)
            ->asForm()->timeout(10)
            ->post($this->baseUrl().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        return $response->json('access_token', '');
    }

    public function createCredentials(string $code): array
    {
        // code format: "client_id|secret"
        $parts = explode('|', $code, 2);

        return [
            'merchant_id' => $parts[0],
            'email' => '',
            'credentials' => ['client_id' => $parts[0], 'secret' => $parts[1] ?? ''],
        ];
    }

    public function createPayment(PaymentRequest $request): PaymentResult
    {
        try {
            $credentials = $request->to->credentials;
            $token = $this->bearerToken($credentials['client_id'] ?? '', $credentials['secret'] ?? '');
            $currency = strtoupper($request->currency);

            $response = Http::withToken($token)->timeout(15)->post($this->baseUrl().'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => (string) ($request->orderRef ?? $request->reference),
                    'description' => 'Order '.($request->orderRef ?? $request->reference),
                    'amount' => ['currency_code' => $currency, 'value' => number_format($request->amount, 2, '.', '')],
                ]],
                'application_context' => [
                    'return_url' => $this->checkoutUrls->success($request),
                    'cancel_url' => $this->checkoutUrls->cancel(),
                    'user_action' => 'PAY_NOW',
                    'shipping_preference' => 'NO_SHIPPING',
                ],
            ]);

            if (! $response->successful()) {
                $this->logger->error('PayPal createPayment failed', ['body' => $response->json()]);

                return new PaymentResult(false, null, null, 'failed', null);
            }

            $body = $response->json();
            $approveUrl = collect($body['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? null;

            return new PaymentResult(
                success: true,
                clientSecret: $approveUrl,
                transactionId: $body['id'],
                status: strtolower($body['status'] ?? 'created'),
                reference: $request->reference,
            );
        } catch (Throwable $e) {
            $this->logger->error('PayPal createPayment error: '.$e->getMessage(), ['exception' => $e]);

            return new PaymentResult(false, null, null, 'failed', null);
        }
    }

    public function refundPayment(string $transactionId, array $credentials, ?float $amount = null): bool
    {
        try {
            $token = $this->bearerToken($credentials['client_id'] ?? '', $credentials['secret'] ?? '');

            // The interface doesn't carry currency; hardcoded per Global Constraints.
            $payload = $amount !== null
                ? ['amount' => ['value' => number_format($amount, 2, '.', ''), 'currency_code' => 'EUR']]
                : [];

            $response = Http::withToken($token)->timeout(15)
                ->post($this->baseUrl().'/v2/payments/captures/'.$transactionId.'/refund', $payload);

            return $response->successful();
        } catch (Throwable $e) {
            $this->logger->error('PayPal refundPayment error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function deactivateCheckout(string $transactionId, array $credentials): bool
    {
        // PayPal orders expire automatically; no explicit cancel endpoint exists for checkout orders.
        return true;
    }

    public function getCheckout(string $checkoutId, array $credentials): ?CheckoutResult
    {
        try {
            $token = $this->bearerToken($credentials['client_id'] ?? '', $credentials['secret'] ?? '');

            $response = Http::withToken($token)->timeout(10)->get($this->baseUrl().'/v2/checkout/orders/'.$checkoutId);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->json();

            $transactions = [];
            foreach ($body['purchase_units'] ?? [] as $unit) {
                foreach ($unit['payments']['captures'] ?? [] as $capture) {
                    $transactions[] = new TransactionResult(
                        providerId: $capture['id'],
                        status: strtolower($capture['status'] ?? ''),
                        currency: strtoupper($capture['amount']['currency_code'] ?? ''),
                        amount: (float) ($capture['amount']['value'] ?? 0),
                        timestamp: $capture['create_time'] ?? '',
                        raw: $capture,
                    );
                }
            }

            $firstUnit = Arr::first($body['purchase_units'] ?? []);

            return new CheckoutResult(
                providerId: $checkoutId,
                status: strtolower($body['status'] ?? ''),
                currency: strtoupper(Arr::get($firstUnit, 'amount.currency_code', 'EUR')),
                amount: (float) Arr::get($firstUnit, 'amount.value', 0),
                timestamp: $body['create_time'] ?? '',
                transactions: $transactions,
                response: $body,
            );
        } catch (Throwable $e) {
            $this->logger->error('PayPal getCheckout error: '.$e->getMessage(), ['exception' => $e]);

            return null;
        }
    }

    public function refreshCredentials(string $refreshToken): array
    {
        return []; // client_credentials OAuth, re-fetched fresh per call — nothing to refresh.
    }
}
