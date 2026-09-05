<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\DTO;

final class PayerContact
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $surname = null,
        public readonly ?string $email = null,
    ) {}
}
