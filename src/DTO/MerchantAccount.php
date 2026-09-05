<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\DTO;

final class MerchantAccount
{
    public function __construct(
        public readonly array $credentials,
        public readonly ?string $merchantId = null,
        public readonly ?string $email = null,
    ) {}
}
