<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Tests;

use LaravelPay\LaravelPay\LaravelPayServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelPayServiceProvider::class,
        ];
    }
}
