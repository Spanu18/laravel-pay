<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\DTO;

class TransactionResult
{
    /**
     * Payment gateway id
     */
    public string $providerId;

    /**
     * Payment gateway status
     */
    public string $status;

    /**
     * Payment gateway currency
     */
    public string $currency;

    /**
     * Payment gateway amount
     */
    public float $amount;

    /**
     * Payment gateway provided timestamp
     */
    public string $timestamp;

    /**
     * Payment gateway raw response
     *
     * @var array<string, mixed>
     */
    public array $raw = [];

    /**
     * Constructor.
     */
    public function __construct(
        string $providerId,
        string $status,
        string $currency,
        float $amount,
        string $timestamp,
        $raw,
    ) {
        $this->providerId = $providerId;
        $this->status = $status;
        $this->currency = $currency;
        $this->amount = $amount;
        $this->timestamp = $timestamp;
        $this->raw = $raw;
    }
}
