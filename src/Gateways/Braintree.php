<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Gateways;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use LaravelPay\LaravelPay\Contracts\PaymentGatewayInterface;
use LaravelPay\LaravelPay\DTO\CheckoutResult;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\DTO\PaymentResult;
use LaravelPay\LaravelPay\DTO\TransactionResult;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class Braintree implements PaymentGatewayInterface
{
    protected const GRAPHQL_URL = 'https://payments.braintree-api.com/graphql';

    protected const GRAPHQL_SANDBOX = 'https://payments.sandbox.braintree-api.com/graphql';

    protected const TOKEN_URL = 'https://api.braintreegateway.com/oauth/access_tokens';

    protected const TOKEN_URL_SANDBOX = 'https://api.sandbox.braintreegateway.com/oauth/access_tokens';

    public function __construct(
        protected string $clientId,
        protected string $clientSecret,
        protected bool $sandbox,
        protected LoggerInterface $logger,
    ) {}

    protected function graphqlUrl(): string
    {
        return $this->sandbox ? self::GRAPHQL_SANDBOX : self::GRAPHQL_URL;
    }

    protected function tokenUrl(): string
    {
        return $this->sandbox ? self::TOKEN_URL_SANDBOX : self::TOKEN_URL;
    }

    protected function graphql(string $accessToken, string $query, array $variables = []): Response
    {
        return Http::withToken($accessToken)
            ->withHeaders(['Braintree-Version' => '2019-01-01', 'Content-Type' => 'application/json'])
            ->timeout(15)
            ->post($this->graphqlUrl(), ['query' => $query, 'variables' => $variables]);
    }

    public function createCredentials(string $code): array
    {
        try {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()->timeout(10)
                ->post($this->tokenUrl(), ['grant_type' => 'authorization_code', 'code' => $code]);

            if (! $response->successful()) {
                $this->logger->error('Braintree createCredentials failed', ['body' => $response->json()]);

                throw new RuntimeException('Braintree token exchange failed');
            }

            $body = $response->json();
            $merchantId = $body['merchant_id'] ?? '';

            return [
                'merchant_id' => $merchantId,
                'email' => '',
                'credentials' => [
                    'access_token' => $body['access_token'],
                    'refresh_token' => $body['refresh_token'] ?? '',
                    'merchant_id' => $merchantId,
                    'expires_at' => now()->addSeconds($body['expires_in'] ?? 7776000)->toDateTimeString(),
                ],
            ];
        } catch (Throwable $e) {
            $this->logger->error('Braintree createCredentials error: '.$e->getMessage(), ['exception' => $e]);

            throw $e;
        }
    }

    public function refreshCredentials(string $refreshToken): array
    {
        try {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()->timeout(10)
                ->post($this->tokenUrl(), ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);

            if (! $response->successful()) {
                $this->logger->error('Braintree refreshCredentials failed', ['body' => $response->json()]);

                return [];
            }

            $body = $response->json();

            return [
                'access_token' => $body['access_token'],
                'refresh_token' => $body['refresh_token'] ?? $refreshToken,
                'expires_at' => now()->addSeconds($body['expires_in'] ?? 7776000)->toDateTimeString(),
            ];
        } catch (Throwable $e) {
            $this->logger->error('Braintree refreshCredentials error: '.$e->getMessage(), ['exception' => $e]);

            return [];
        }
    }

    /**
     * Returns a Braintree client token as clientSecret. The frontend initialises the
     * Braintree Drop-in UI with this token, obtains a payment_method_nonce, then
     * submits it to whatever server-side action calls chargeNonce().
     */
    public function createPayment(PaymentRequest $request): PaymentResult
    {
        try {
            $accessToken = $request->to->credentials['access_token'] ?? '';

            $query = <<<'GQL'
            mutation CreateClientToken($input: CreateClientTokenInput) {
              createClientToken(input: $input) {
                clientToken
              }
            }
            GQL;

            $response = $this->graphql($accessToken, $query, ['input' => []]);

            if (! $response->successful()) {
                $this->logger->error('Braintree createPayment failed', ['body' => $response->json()]);

                return new PaymentResult(false, null, null, 'failed', null);
            }

            $clientToken = Arr::get($response->json(), 'data.createClientToken.clientToken');

            if (! $clientToken) {
                $this->logger->error('Braintree empty client token', ['body' => $response->json()]);

                return new PaymentResult(false, null, null, 'failed', null);
            }

            return new PaymentResult(
                success: true,
                clientSecret: $clientToken,
                transactionId: $clientToken,
                status: 'pending_nonce',
                reference: $request->reference,
            );
        } catch (Throwable $e) {
            $this->logger->error('Braintree createPayment error: '.$e->getMessage(), ['exception' => $e]);

            return new PaymentResult(false, null, null, 'failed', null);
        }
    }

    /**
     * Complete a Braintree sale after the frontend submits a payment_method_nonce.
     * $nonce is the value returned by Drop-in UI. Returns the real Braintree
     * transaction ID for storage as OrderPayment.transaction_id.
     */
    public function chargeNonce(string $accessToken, string $nonce, float $amount, string $orderRef): ?string
    {
        try {
            $query = <<<'GQL'
            mutation ChargePaymentMethod($input: ChargePaymentMethodInput!) {
              chargePaymentMethod(input: $input) {
                transaction {
                  id
                  legacyId
                  status
                }
              }
            }
            GQL;

            $response = $this->graphql($accessToken, $query, [
                'input' => [
                    'paymentMethodId' => $nonce,
                    'transaction' => [
                        'amount' => number_format($amount, 2, '.', ''),
                        'orderId' => $orderRef,
                        'options' => ['submitForSettlement' => true],
                    ],
                ],
            ]);

            if (! $response->successful()) {
                $this->logger->error('Braintree chargeNonce failed', ['body' => $response->json()]);

                return null;
            }

            return Arr::get($response->json(), 'data.chargePaymentMethod.transaction.legacyId');
        } catch (Throwable $e) {
            $this->logger->error('Braintree chargeNonce error: '.$e->getMessage(), ['exception' => $e]);

            return null;
        }
    }

    public function refundPayment(string $transactionId, array $credentials, ?float $amount = null): bool
    {
        try {
            $query = <<<'GQL'
            mutation RefundTransaction($input: ReverseTransactionInput!) {
              reverseTransaction(input: $input) {
                reversal {
                  ... on Refund {
                    id
                    status
                  }
                }
              }
            }
            GQL;

            $variables = ['input' => ['transactionId' => $transactionId]];

            if ($amount !== null) {
                $variables['input']['amount'] = number_format($amount, 2, '.', '');
            }

            $response = $this->graphql($credentials['access_token'] ?? '', $query, $variables);

            return $response->successful() && empty(Arr::get($response->json(), 'errors'));
        } catch (Throwable $e) {
            $this->logger->error('Braintree refundPayment error: '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }

    public function deactivateCheckout(string $transactionId, array $credentials): bool
    {
        // Braintree client tokens expire after 3 days; no explicit cancel needed before nonce submission.
        return true;
    }

    public function getCheckout(string $checkoutId, array $credentials): ?CheckoutResult
    {
        try {
            $query = <<<'GQL'
            query GetTransaction($id: ID!) {
              node(id: $id) {
                ... on Transaction {
                  id
                  legacyId
                  status
                  amount { value currencyCode }
                  createdAt
                }
              }
            }
            GQL;

            $response = $this->graphql($credentials['access_token'] ?? '', $query, ['id' => $checkoutId]);

            if (! $response->successful()) {
                return null;
            }

            $tx = Arr::get($response->json(), 'data.node');

            if (! $tx) {
                return null;
            }

            $transaction = new TransactionResult(
                providerId: Arr::get($tx, 'legacyId', $checkoutId),
                status: strtolower(Arr::get($tx, 'status', '')),
                currency: strtoupper(Arr::get($tx, 'amount.currencyCode', 'EUR')),
                amount: (float) Arr::get($tx, 'amount.value', 0),
                timestamp: Arr::get($tx, 'createdAt', ''),
                raw: $tx,
            );

            return new CheckoutResult(
                providerId: $checkoutId,
                status: strtolower(Arr::get($tx, 'status', '')),
                currency: strtoupper(Arr::get($tx, 'amount.currencyCode', 'EUR')),
                amount: (float) Arr::get($tx, 'amount.value', 0),
                timestamp: Arr::get($tx, 'createdAt', ''),
                transactions: [$transaction],
                response: $response->json(),
            );
        } catch (Throwable $e) {
            $this->logger->error('Braintree getCheckout error: '.$e->getMessage(), ['exception' => $e]);

            return null;
        }
    }
}
