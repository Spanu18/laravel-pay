<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\DTO;

class PaymentResult
{
    /**
     * Whether the payment action succeeded.
     */
    public bool $success;

    /**
     * The client secret for front-end confirmation (e.g., Stripe PaymentIntent client_secret or SumUp checkout URL).
     */
    public ?string $clientSecret;

    /**
     * The transaction identifier returned by the payment provider.
     */
    public ?string $transactionId;

    /**
     * The status of the payment at the time of creation or refund.
     */
    public string $status;

    public ?string $reference;

    /**
     * When the gateway had to rotate the merchant's stored credentials mid-call
     * (e.g. a stale access token refreshed and retried), the new credentials to
     * persist. Null when nothing needs to be persisted.
     */
    public ?array $refreshedCredentials;

    /**
     * Constructor.
     */
    public function __construct(
        bool $success,
        ?string $clientSecret,
        ?string $transactionId,
        string $status,
        ?string $reference,
        ?array $refreshedCredentials = null,
    ) {
        $this->success = $success;
        $this->clientSecret = $clientSecret;
        $this->transactionId = $transactionId;
        $this->status = $status;
        $this->reference = $reference;
        $this->refreshedCredentials = $refreshedCredentials;
    }
}
