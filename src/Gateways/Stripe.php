<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Gateways;

use LaravelPay\LaravelPay\Contracts\CheckoutUrls;
use LaravelPay\LaravelPay\Contracts\PaymentGatewayInterface;
use LaravelPay\LaravelPay\DTO\CheckoutResult;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\DTO\PaymentResult;
use LaravelPay\LaravelPay\DTO\TransactionResult;
use Psr\Log\LoggerInterface;
use Stripe\OAuth;
use Stripe\StripeClient;
use Throwable;

class Stripe implements PaymentGatewayInterface
{
    public function __construct(
        protected ?string $platformSecretKey,
        protected LoggerInterface $logger,
        protected CheckoutUrls $checkoutUrls,
    ) {}

    protected function resolveKey(array $credentials): string
    {
        // Stripe Connect stores the connected account's access_token as the secret
        // key. Falls back to the platform's own key when no restaurant-specific
        // credentials exist yet — mirrors PaymentGatewayFactory's pre-migration
        // fromProviderCode('stripe') fallback, moved here since the gateway no
        // longer receives credentials at construction time.
        return $credentials['access_token'] ?? $credentials['secret_key'] ?? $this->platformSecretKey ?? '';
    }

    protected function clientFor(array $credentials): StripeClient
    {
        return new StripeClient($this->resolveKey($credentials));
    }

    /**
     * Exchange a Stripe Connect OAuth code for the connected account's credentials.
     * Token exchange must use the platform's own secret key, not a connected
     * account's — Stripe rejects it otherwise.
     */
    public function createCredentials(string $code): array
    {
        try {
            \Stripe\Stripe::setApiKey($this->platformSecretKey ?? '');

            $response = OAuth::token(['grant_type' => 'authorization_code', 'code' => $code]);

            $stripeUserId = $response['stripe_user_id'];
            $accessToken = $response['access_token'];

            return [
                'merchant_id' => $stripeUserId,
                'email' => '',
                'credentials' => ['access_token' => $accessToken, 'stripe_user_id' => $stripeUserId],
            ];
        } catch (Throwable $e) {
            $this->logger->error('Stripe createCredentials error: '.$e->getMessage(), ['exception' => $e]);

            throw $e;
        }
    }

    /**
     * Create a Stripe Checkout Session (hosted payment page). Returns the session
     * URL as clientSecret so the frontend can redirect.
     */
    public function createPayment(PaymentRequest $request): PaymentResult
    {
        try {
            $credentials = $request->to->credentials;
            $key = $this->resolveKey($credentials);

            if ($key === '') {
                $this->logger->error('Stripe createPayment failed: no secret key available');

                return new PaymentResult(false, null, null, 'failed', null);
            }

            $successUrl = $this->checkoutUrls->success($request).'&session_id={CHECKOUT_SESSION_ID}';

            $session = (new StripeClient($key))->checkout->sessions->create([
                'mode' => 'payment',
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower($request->currency),
                        'unit_amount' => (int) round($request->amount * 100),
                        'product_data' => ['name' => 'Order '.($request->orderRef ?? $request->reference)],
                    ],
                    'quantity' => 1,
                ]],
                'metadata' => [
                    'order_ref' => (string) ($request->orderRef ?? ''),
                    'cart_hash' => $request->reference,
                ],
                'payment_intent_data' => [
                    'metadata' => ['order_ref' => (string) ($request->orderRef ?? '')],
                ],
                'success_url' => $successUrl,
                'cancel_url' => $this->checkoutUrls->cancel(),
            ]);

            return new PaymentResult(
                success: true,
                clientSecret: $session->url,
                transactionId: $session->id,
                status: $session->status,
                reference: $request->reference,
            );
        } catch (Throwable $e) {
            $this->logger->error('Stripe createPayment error: '.$e->getMessage(), ['exception' => $e]);

            return new PaymentResult(false, null, null, 'failed', null);
        }
    }

    public function refundPayment(string $transactionId, array $credentials, ?float $amount = null): bool
    {
        try {
            $params = ['payment_intent' => $transactionId];

            if ($amount !== null) {
                $params['amount'] = (int) round($amount * 100);
            }

            $refund = $this->clientFor($credentials)->refunds->create($params);

            return $refund->status === 'succeeded';
        } catch (Throwable $e) {
            $this->logger->error('Stripe refundPayment error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function deactivateCheckout(string $transactionId, array $credentials): bool
    {
        try {
            $session = $this->clientFor($credentials)->checkout->sessions->expire($transactionId);

            return $session->status === 'expired';
        } catch (Throwable $e) {
            $this->logger->error('Stripe deactivateCheckout error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function getCheckout(string $checkoutId, array $credentials): ?CheckoutResult
    {
        try {
            $session = $this->clientFor($credentials)->checkout->sessions->retrieve($checkoutId, [
                'expand' => ['payment_intent'],
            ]);

            $transactions = [];
            $pi = $session->payment_intent;

            if ($pi) {
                $transactions[] = new TransactionResult(
                    providerId: $pi->id,
                    status: $pi->status,
                    currency: strtoupper($pi->currency ?? ''),
                    amount: ($pi->amount ?? 0) / 100,
                    timestamp: $pi->created ? date('c', $pi->created) : '',
                    raw: $pi->toArray(),
                );
            }

            return new CheckoutResult(
                providerId: $session->id,
                status: $session->status,
                currency: strtoupper($session->currency ?? ''),
                amount: ($session->amount_total ?? 0) / 100,
                timestamp: $session->created ? date('c', $session->created) : '',
                transactions: $transactions,
                response: $session->toArray(),
            );
        } catch (Throwable $e) {
            $this->logger->error('Stripe getCheckout error: '.$e->getMessage(), ['exception' => $e]);

            return null;
        }
    }

    public function refreshCredentials(string $refreshToken): array
    {
        return []; // Stripe Connect's standard OAuth access tokens don't expire — nothing to refresh.
    }
}
