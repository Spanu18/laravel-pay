<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\DTO;

use Illuminate\Support\Str;

final class PaymentRequest
{
    public readonly string $reference;

    public function __construct(
        public readonly float $amount,
        public readonly string $currency,
        public readonly MerchantAccount $to,
        public readonly string $locale,
        public readonly string $purchaseCountry,
        public readonly ?PayerContact $from = null,
        public readonly string|int|null $orderRef = null,
        ?string $reference = null,
    ) {
        $this->reference = $reference ?? (string) Str::uuid();
    }
}
