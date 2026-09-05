<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \LaravelPay\LaravelPay\LaravelPay
 */
class LaravelPay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \LaravelPay\LaravelPay\LaravelPay::class;
    }
}
