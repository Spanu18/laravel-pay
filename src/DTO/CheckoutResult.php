<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\DTO;

class CheckoutResult
{
    /**
     * The ID provider by the payment gateway
     */
    public string $providerId;

    /**
     * The payment gateway status
     */
    public string $status;

    /**
     * The checkout currency
     */
    public ?string $currency;

    /**
     * The checkout amount
     */
    public float $amount;

    /**
     * Payment gateway given timestamp
     */
    public ?string $timestamp;

    /**
     * @var list<TransactionResult>|array<array<string,mixed>>
     */
    public array $transactions = [];

    /**
     * Payment gateway original response
     */
    public array $response;

    /**
     * Constructor.
     */
    public function __construct(
        string $providerId,
        string $status,
        ?string $currency,
        float $amount,
        ?string $timestamp,
        array $transactions,
        array $response,
    ) {
        $this->providerId = $providerId;
        $this->status = $status;
        $this->currency = $currency;
        $this->amount = $amount;
        $this->timestamp = $timestamp;
        $this->transactions = $transactions;
        $this->response = $response;
    }
}
