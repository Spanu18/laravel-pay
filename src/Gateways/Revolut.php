<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Gateways;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LaravelPay\LaravelPay\Contracts\PaymentGatewayInterface;
use LaravelPay\LaravelPay\DTO\CheckoutResult;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\DTO\PaymentResult;
use LaravelPay\LaravelPay\DTO\TransactionResult;
use Psr\Log\LoggerInterface;
use Throwable;

class Revolut implements PaymentGatewayInterface
{
    // Inherited from the legacy gateway verbatim: always the sandbox host, no
    // live/sandbox branch exists anywhere in this class. Not introduced or
    // fixed by this migration — flagged for a separate decision.
    protected const API_BASE = 'https://sandbox-merchant.revolut.com/api';

    public function __construct(
        protected LoggerInterface $logger,
    ) {}

    protected function http(string $privateKey)
    {
        return Http::withHeaders([
            'Accept' => 'application/json',
            'Revolut-Api-Version' => '2024-09-01',
            'Authorization' => 'Bearer '.$privateKey,
        ])->timeout(10);
    }

    public function createCredentials(string $code): array
    {
        // code format: "private_key:public_key"
        $parts = explode(':', $code, 2);

        return [
            // Legacy Revolut gateway stored an integer 0 (not a string) here — matched
            // exactly so re-linking an already-linked restaurant updates the existing
            // config instead of creating a duplicate.
            'merchant_id' => 0,
            // Inherited from the legacy gateway verbatim — a hardcoded placeholder,
            // not fixed here since this migration is behavior-preserving.
            'email' => 'a@a.com',
            'credentials' => ['public_key' => $parts[1] ?? '', 'private_key' => $parts[0] ?? ''],
        ];
    }

    public function createPayment(PaymentRequest $request): PaymentResult
    {
        try {
            $credentials = $request->to->credentials;

            // No return_url/cancel_url field is sent — the legacy gateway never sent
            // one either. PaymentRequest also carries no billing address field, so
            // the legacy billing_address block (built from $order->address) is
            // dropped here — see Global Constraints.
            $response = $this->http($credentials['private_key'] ?? '')->post(self::API_BASE.'/orders', [
                'amount' => (int) round($request->amount * 100),
                'currency' => strtoupper($request->currency),
                'description' => 'Order '.($request->orderRef ?? $request->reference),
            ]);

            if (! $response->successful()) {
                $this->logger->error('Revolut createPayment failed', ['body' => $response->json()]);

                return new PaymentResult(false, null, null, 'failed', null);
            }

            $body = $response->json();
            $checkoutToken = $body['token'];

            return new PaymentResult(
                success: true,
                clientSecret: $checkoutToken,
                transactionId: $checkoutToken,
                status: $body['state'] ?? 'created',
                reference: $request->reference,
            );
        } catch (Throwable $e) {
            $this->logger->error('Revolut createPayment error: '.$e->getMessage(), ['exception' => $e]);

            return new PaymentResult(false, null, null, 'failed', null);
        }
    }

    public function refundPayment(string $transactionId, array $credentials, ?float $amount = null): bool
    {
        try {
            // The interface doesn't carry currency; hardcoded per Global Constraints.
            $payload = ['currency' => 'EUR'];

            if ($amount !== null) {
                $payload['amount'] = (int) round($amount * 100);
            }

            $response = $this->http($credentials['private_key'] ?? '')->post(self::API_BASE.'/orders/'.$transactionId.'/refund', $payload);

            return $response->successful();
        } catch (Throwable $e) {
            $this->logger->error('Revolut refundPayment error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function deactivateCheckout(string $transactionId, array $credentials): bool
    {
        try {
            $this->http($credentials['private_key'] ?? '')->post(self::API_BASE.'/orders/'.$transactionId.'/cancel');

            return true;
        } catch (Throwable $e) {
            $this->logger->error('Revolut deactivateCheckout error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function getCheckout(string $checkoutId, array $credentials): ?CheckoutResult
    {
        $response = $this->http($credentials['private_key'] ?? '')->get(self::API_BASE.'/orders/'.$checkoutId);

        if (! $response->successful()) {
            return null;
        }

        $body = $response->json();

        $payments = Arr::get($body, 'payments', []);

        if (is_array($payments) && isset($payments['data']) && is_array($payments['data'])) {
            $payments = $payments['data'];
        }

        if (! is_iterable($payments)) {
            $payments = [];
        }

        $transactions = [];
        foreach ($payments as $p) {
            $transactions[] = new TransactionResult(
                providerId: (string) (Arr::get($p, 'id') ?? Arr::get($p, 'payment_id') ?? Str::uuid()->toString()),
                status: (string) (Arr::get($p, 'state') ?? Arr::get($p, 'status') ?? ''),
                currency: Arr::get($p, 'currency') ? strtoupper((string) Arr::get($p, 'currency')) : null,
                amount: Arr::get($p, 'amount') / 100,
                timestamp: (string) (Arr::get($p, 'created_at') ?? Arr::get($p, 'createdDate') ?? Arr::get($p, 'timestamp') ?? ''),
                raw: $p,
            );
        }

        $orderCurrency = Arr::get($body, 'currency');
        $orderAmount = Arr::get($body, 'amount') / 100;
        $orderStatus = Arr::get($body, 'state') ?? Arr::get($body, 'status') ?? '';

        return new CheckoutResult(
            providerId: (string) (Arr::get($body, 'id') ?? $checkoutId),
            status: (string) $orderStatus,
            currency: $orderCurrency ? strtoupper((string) $orderCurrency) : null,
            amount: $orderAmount,
            timestamp: (string) (Arr::get($body, 'created_at') ?? Arr::get($body, 'createdDate') ?? Arr::get($body, 'timestamp') ?? ''),
            transactions: $transactions,
            response: $body,
        );
    }

    public function refreshCredentials(string $refreshToken): array
    {
        return []; // static public_key/private_key credentials — nothing to refresh.
    }
}
