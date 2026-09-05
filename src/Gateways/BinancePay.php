<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Gateways;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LaravelPay\LaravelPay\Contracts\CheckoutUrls;
use LaravelPay\LaravelPay\Contracts\PaymentGatewayInterface;
use LaravelPay\LaravelPay\DTO\CheckoutResult;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\DTO\PaymentResult;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Binance Pay gateway (hosted crypto checkout). Requests are signed per Binance
 * Pay v3 spec: HMAC-SHA512 of "${timestamp}\n${nonce}\n${body}\n" with the
 * merchant's secret key, uppercase hex.
 */
class BinancePay implements PaymentGatewayInterface
{
    protected const API_BASE = 'https://bpay.binanceapi.com';

    public function __construct(
        protected LoggerInterface $logger,
        protected CheckoutUrls $checkoutUrls,
    ) {}

    protected function signedPost(string $path, array $payload, string $apiKey, string $apiSecret)
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) round(microtime(true) * 1000);
        $nonce = Str::random(32);

        $signature = strtoupper(hash_hmac('sha512', $timestamp."\n".$nonce."\n".$body."\n", $apiSecret));

        return Http::withHeaders([
            'Content-Type' => 'application/json',
            'BinancePay-Timestamp' => $timestamp,
            'BinancePay-Nonce' => $nonce,
            'BinancePay-Certificate-SN' => $apiKey,
            'BinancePay-Signature' => $signature,
        ])->timeout(15)->withBody($body, 'application/json')->post(self::API_BASE.$path);
    }

    public function createCredentials(string $code): array
    {
        // code format: "api_key|api_secret"
        [$apiKey, $apiSecret] = array_pad(explode('|', $code, 2), 2, '');

        return [
            // Matched to the legacy Binance Pay gateway's stored value so re-linking an
            // already-linked restaurant updates the existing config instead of
            // creating a duplicate.
            'merchant_id' => '',
            'email' => '',
            'credentials' => ['api_key' => $apiKey, 'api_secret' => $apiSecret],
        ];
    }

    public function createPayment(PaymentRequest $request): PaymentResult
    {
        try {
            $credentials = $request->to->credentials;
            $apiKey = $credentials['api_key'] ?? '';
            $apiSecret = $credentials['api_secret'] ?? '';
            $merchantTradeNo = $this->merchantTradeNo($request);

            $response = $this->signedPost('/binancepay/openapi/v3/order', [
                'env' => ['terminalType' => 'WEB'],
                'merchantTradeNo' => $merchantTradeNo,
                'orderAmount' => number_format($request->amount, 2, '.', ''),
                'currency' => strtoupper($request->currency),
                'goods' => [
                    'goodsType' => '02',
                    'goodsCategory' => '6000',
                    'referenceGoodsId' => (string) ($request->orderRef ?? $request->reference),
                    'goodsName' => 'Order '.($request->orderRef ?? $request->reference),
                ],
                'returnUrl' => $this->checkoutUrls->success($request),
                'cancelUrl' => $this->checkoutUrls->cancel(),
            ], $apiKey, $apiSecret);

            $json = $response->json();

            if (! $response->successful() || ($json['status'] ?? '') !== 'SUCCESS') {
                $this->logger->error('Binance Pay createPayment failed', ['body' => $json]);

                return new PaymentResult(false, null, null, 'failed', null);
            }

            $data = $json['data'] ?? [];

            return new PaymentResult(
                success: true,
                clientSecret: Arr::get($data, 'checkoutUrl') ?? Arr::get($data, 'universalUrl'),
                transactionId: Arr::get($data, 'prepayId'),
                status: 'pending',
                reference: $request->reference,
            );
        } catch (Throwable $e) {
            $this->logger->error('Binance Pay createPayment error: '.$e->getMessage(), ['exception' => $e]);

            return new PaymentResult(false, null, null, 'failed', null);
        }
    }

    public function refundPayment(string $transactionId, array $credentials, ?float $amount = null): bool
    {
        try {
            $payload = ['refundRequestId' => (string) Str::uuid(), 'prepayId' => $transactionId];

            if ($amount !== null) {
                $payload['refundAmount'] = number_format($amount, 2, '.', '');
            }

            $response = $this->signedPost(
                '/binancepay/openapi/order/refund',
                $payload,
                $credentials['api_key'] ?? '',
                $credentials['api_secret'] ?? '',
            );

            return $response->successful() && $response->json('status') === 'SUCCESS';
        } catch (Throwable $e) {
            $this->logger->error('Binance Pay refundPayment error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function deactivateCheckout(string $transactionId, array $credentials): bool
    {
        try {
            $response = $this->signedPost(
                '/binancepay/openapi/order/close',
                ['prepayId' => $transactionId],
                $credentials['api_key'] ?? '',
                $credentials['api_secret'] ?? '',
            );

            return $response->successful() && $response->json('status') === 'SUCCESS';
        } catch (Throwable $e) {
            $this->logger->error('Binance Pay deactivateCheckout error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function getCheckout(string $checkoutId, array $credentials): ?CheckoutResult
    {
        try {
            $response = $this->signedPost(
                '/binancepay/openapi/v2/order/query',
                ['prepayId' => $checkoutId],
                $credentials['api_key'] ?? '',
                $credentials['api_secret'] ?? '',
            );

            if (! $response->successful() || $response->json('status') !== 'SUCCESS') {
                return null;
            }

            $data = $response->json('data', []);

            return new CheckoutResult(
                providerId: (string) (Arr::get($data, 'prepayId') ?? $checkoutId),
                status: $this->mapStatus((string) Arr::get($data, 'status', '')),
                currency: strtoupper((string) Arr::get($data, 'currency', 'EUR')),
                amount: (float) Arr::get($data, 'orderAmount', 0),
                timestamp: (string) Arr::get($data, 'createTime', ''),
                transactions: [],
                response: $data,
            );
        } catch (Throwable $e) {
            $this->logger->error('Binance Pay getCheckout error: '.$e->getMessage(), ['exception' => $e]);

            return null;
        }
    }

    public function refreshCredentials(string $refreshToken): array
    {
        return []; // static api_key/api_secret credentials — nothing to refresh.
    }

    /**
     * Verify an inbound webhook notification signed by Binance Pay. Binance signs
     * with their RSA private key and sends BinancePay-Timestamp/Nonce/Signature
     * (base64); we verify against the merchant's published certificate (cached).
     */
    public function verifyWebhookSignature(
        string $rawBody,
        string $timestamp,
        string $nonce,
        string $signature,
        array $credentials,
    ): bool {
        try {
            if ($timestamp === '' || $nonce === '' || $signature === '') {
                return false;
            }

            $payload = $timestamp."\n".$nonce."\n".$rawBody."\n";
            $decodedSig = base64_decode($signature, true);

            if ($decodedSig === false) {
                return false;
            }

            foreach ($this->certificates($credentials) as $publicKey) {
                $pem = $this->normalizePublicKey($publicKey);

                if (openssl_verify($payload, $decodedSig, $pem, OPENSSL_ALGO_SHA256) === 1) {
                    return true;
                }
            }

            return false;
        } catch (Throwable $e) {
            $this->logger->error('Binance Pay webhook verify error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    protected function mapStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'PAID' => 'paid',
            'PENDING' => 'pending',
            'CANCELED' => 'canceled',
            'EXPIRED' => 'expired',
            'REFUNDED' => 'refunded',
            'REFUNDING' => 'refunding',
            'ERROR' => 'failed',
            default => 'created',
        };
    }

    protected function merchantTradeNo(PaymentRequest $request): string
    {
        return substr(($request->orderRef ?? $request->reference).strtoupper(Str::random(20)), 0, 32);
    }

    /**
     * Fetch (and cache) the merchant's RSA public certificates from Binance.
     *
     * @return array<int, string> PEM/base64 public keys.
     */
    protected function certificates(array $credentials): array
    {
        $apiKey = $credentials['api_key'] ?? '';
        $cacheKey = 'binance_pay:certs:'.hash('sha256', $apiKey);

        $cached = Cache::get($cacheKey);

        if (is_array($cached) && ! empty($cached)) {
            return $cached;
        }

        $response = $this->signedPost('/binancepay/openapi/certificates', [], $apiKey, $credentials['api_secret'] ?? '');

        if (! $response->successful() || $response->json('status') !== 'SUCCESS') {
            $this->logger->error('Binance Pay certificates fetch failed', ['body' => $response->json()]);

            return [];
        }

        $certs = collect($response->json('data', []))->pluck('certPublic')->filter()->values()->all();

        if (! empty($certs)) {
            Cache::put($cacheKey, $certs, now()->addDay());
        }

        return $certs;
    }

    protected function normalizePublicKey(string $key): string
    {
        $key = trim($key);

        if (str_contains($key, 'BEGIN')) {
            return $key;
        }

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split($key, 64, "\n")."-----END PUBLIC KEY-----\n";
    }
}
