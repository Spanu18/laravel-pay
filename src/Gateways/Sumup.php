<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Gateways;

use Illuminate\Support\Arr;
use LaravelPay\LaravelPay\Contracts\PaymentGatewayInterface;
use LaravelPay\LaravelPay\DTO\CheckoutResult;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\DTO\PaymentResult;
use LaravelPay\LaravelPay\DTO\TransactionResult;
use Psr\Log\LoggerInterface;
use SumUp\HttpClient\HttpClientInterface;
use SumUp\Services\TransactionsRefundRequest;
use SumUp\SumUp as SumupSdk;
use SumUp\Types\Checkout;
use SumUp\Types\CheckoutCreateRequest;
use SumUp\Types\CheckoutSuccess;
use SumUp\Types\HostedCheckout;
use Throwable;

class Sumup implements PaymentGatewayInterface
{
    public function __construct(
        protected string $apiKey,
        protected LoggerInterface $logger,
        protected ?HttpClientInterface $httpClient = null,
    ) {}

    /**
     * Build the SumUp SDK client for the given credentials.
     *
     * @param  array<string, mixed>  $credentials
     */
    protected function clientForMerchant(array $credentials): SumupSdk
    {
        $config = [
            'api_key' => $credentials['api_key'] ?? $this->apiKey,
        ];

        if ($this->httpClient !== null) {
            $config['client'] = $this->httpClient;
        }

        return new SumupSdk($config);
    }

    /**
     * createCredentials accepts "api_key|merchant_code". The merchant code is the
     * short alphanumeric identifier required when creating checkouts.
     *
     * @return array{merchant_id?: string|null, email?: string|null, credentials: array}
     */
    public function createCredentials(string $code): array
    {
        [$apiKey, $merchantCode] = array_pad(explode('|', $code, 2), 2, '');

        return [
            'merchant_id' => $merchantCode !== '' ? $merchantCode : null,
            'email' => '',
            'credentials' => ['api_key' => $apiKey],
        ];
    }

    public function refreshCredentials(string $refreshToken): array
    {
        return []; // static long-lived API key — nothing to refresh.
    }

    public function createPayment(PaymentRequest $request): PaymentResult
    {
        $credentials = $request->to->credentials;

        try {
            // Enable hosted checkout so SumUp returns a hosted payment page URL.
            $hosted = new HostedCheckout;
            $hosted->enabled = true;

            $sumup = $this->clientForMerchant($credentials);

            $checkout = $sumup->checkouts()->create(new CheckoutCreateRequest(
                checkoutReference: $request->reference,
                amount: $request->amount,
                currency: strtoupper($request->currency),
                merchantCode: $request->to->merchantId ?? '',
                description: 'Order '.($request->orderRef ?? $request->reference),
                redirectUrl: $this->successUrl($request),
                hostedCheckout: $hosted,
            ));

            return new PaymentResult(
                success: true,
                clientSecret: $checkout->hostedCheckoutUrl,
                transactionId: $checkout->id,
                status: $this->normalizeStatus($checkout),
                reference: $request->reference,
            );
        } catch (Throwable $e) {
            $this->logger->error('SumUp createPayment error: '.$e->getMessage(), ['exception' => $e]);

            return new PaymentResult(false, null, null, 'failed', null);
        }
    }

    public function refundPayment(string $transactionId, array $credentials, ?float $amount = null): bool
    {
        try {
            $merchantCode = $credentials['merchant_code'] ?? '';
            $body = $amount !== null ? new TransactionsRefundRequest($amount) : null;

            $this->clientForMerchant($credentials)
                ->transactions()
                ->refund($merchantCode, $transactionId, $body);

            $this->logger->info('SumUp refund success', ['transaction_id' => $transactionId, 'amount' => $amount]);

            return true;
        } catch (Throwable $e) {
            $this->logger->error('SumUp refund failed: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function deactivateCheckout(string $transactionId, array $credentials): bool
    {
        try {
            $this->clientForMerchant($credentials)->checkouts()->deactivate($transactionId);

            return true;
        } catch (Throwable $e) {
            $this->logger->error('SumUp deactivate error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function getCheckout(string $checkoutId, array $credentials): ?CheckoutResult
    {
        try {
            $checkout = $this->clientForMerchant($credentials)->checkouts()->get($checkoutId);

            $raw = $this->toArray($checkout);

            $transactions = $this->buildTransactions($checkout);

            return new CheckoutResult(
                providerId: $checkout->id ?? $checkoutId,
                status: $this->normalizeCheckoutStatus($checkout->status ?? ''),
                currency: $checkout->currency ? strtoupper((string) $checkout->currency) : null,
                amount: (float) ($checkout->amount ?? 0),
                timestamp: $checkout->date,
                transactions: $transactions,
                response: $raw,
            );
        } catch (Throwable $e) {
            $this->logger->error('SumUp getCheckout failed: '.$e->getMessage(), ['exception' => $e]);

            return null;
        }
    }

    protected function successUrl(PaymentRequest $request): string
    {
        return url('/payment/success?reference='.$request->reference);
    }

    protected function normalizeStatus(Checkout $checkout): string
    {
        return strtolower($checkout->status->value ?? 'created');
    }

    protected function normalizeCheckoutStatus(string $status): string
    {
        return strtolower($status !== '' ? $status : 'unknown');
    }

    /**
     * @return list<TransactionResult>
     */
    protected function buildTransactions(CheckoutSuccess $checkout): array
    {
        $transactions = [];

        $items = $checkout->transactions ?? [];

        foreach ($items as $tx) {
            $tx = is_array($tx) ? $tx : $this->toArray($tx);
            $transactions[] = new TransactionResult(
                providerId: (string) ($tx['id'] ?? $tx['transaction_id'] ?? $tx['code'] ?? $checkout->transactionId ?? ''),
                status: strtolower((string) ($tx['status'] ?? $checkout->status ?? '')),
                currency: isset($tx['currency'])
                    ? strtoupper((string) $tx['currency'])
                    : ($checkout->currency ? strtoupper((string) $checkout->currency) : 'EUR'),
                amount: (float) ($tx['amount'] ?? $checkout->amount ?? 0),
                timestamp: (string) ($tx['timestamp'] ?? $tx['created_at'] ?? $tx['date'] ?? $checkout->date ?? ''),
                raw: $tx,
            );
        }

        return $transactions;
    }

    /**
     * Convert an arbitrary object to a plain array for response payloads.
     *
     * @param  array<string, mixed>|object  $value
     * @return array<string, mixed>
     */
    protected function toArray(object|array $value): array
    {
        $json = json_encode($value);

        return $json === false ? [] : Arr::wrap(json_decode($json, true));
    }
}
