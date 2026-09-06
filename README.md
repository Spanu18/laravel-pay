<div align="center">
    <h1>Laravel Pay</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/spanu18/laravel-pay"><img src="https://img.shields.io/packagist/v/spanu18/laravel-pay.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/spanu18/laravel-pay"><img src="https://img.shields.io/packagist/php-v/spanu18/laravel-pay.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/spanu18/laravel-pay"><img src="https://badge.laravel.cloud/badge/spanu18/laravel-pay?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/Spanu18/laravel-pay/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/Spanu18/laravel-pay/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/spanu18/laravel-pay"><img src="https://img.shields.io/packagist/dt/spanu18/laravel-pay.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Multi-gateway payment processing for Laravel. **Laravel Pay** provides a uniform interface over a large set of payment gateways so you can accept payments without rewriting your integration for each provider.

## Installation

You can install the package via Composer:

```bash
composer require spanu18/laravel-pay
```

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="laravel-pay"
```

Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="laravel-pay-config"
```

### Publishing and Running the Migrations

```bash
php artisan vendor:publish --tag="laravel-pay-migrations"
php artisan migrate
```

### Publishing the Views

```bash
php artisan vendor:publish --tag="laravel-pay-views"
```

### Publishing the Translations

```bash
php artisan vendor:publish --tag="laravel-pay-lang"
```

### Publishing the Public Assets

```bash
php artisan vendor:publish --tag="laravel-pay-assets"
```

## Usage

Laravel Pay exposes a uniform contract over all supported gateways. Each gateway is a service in the container and implements `PaymentGatewayInterface`, so the calling code barely changes from gateway to gateway.

### Resolve a gateway

```php
use LaravelPay\LaravelPay\Gateways\Stripe;
use LaravelPay\LaravelPay\Gateways\Sumup;

// Gateway instances are resolved through the container/DI.
$stripe = app(Stripe::class);
$sumup  = app(Sumup::class);
```

### Create a payment

```php
use LaravelPay\LaravelPay\DTO\MerchantAccount;
use LaravelPay\LaravelPay\DTO\PayerContact;
use LaravelPay\LaravelPay\DTO\PaymentRequest;

$payment = new PaymentRequest(
    amount: 49.00,
    currency: 'EUR',
    to: new MerchantAccount(
        credentials: ['access_token' => '...'],   // gateway-specific
        merchantId: 'acct_...',                    // gateway-specific
    ),
    locale: 'it-IT',
    purchaseCountry: 'IT',
    from: new PayerContact(name: 'Mario', email: 'mario@example.com'),
    orderRef: 42,
);

$result = $sumup->createPayment($payment);

if ($result->success) {
    // $result->clientSecret is a hosted checkout URL / client secret to
    // send to your front-end to redirect the payer.
    return redirect($result->clientSecret);
}
```

### Refund and inspect a checkout

```php
$sumup->refundPayment($result->transactionId, $credentials, 49.00);

$checkout = $sumup->getCheckout($result->transactionId, $credentials);
if ($checkout && $checkout->status === 'paid') {
    // fulfill the order
}
```

### Configure gateways

Gateways read their credentials from the package config, which you publish and edit:

```bash
php artisan vendor:publish --tag="laravel-pay-config"
# edit config/laravel-pay.php and set the env vars / keys for each gateway
```

## Supported gateways

Adyen, Axerve, Binance Pay, Braintree, Coinbase, Edenred, Klarna, Mollie, Nexi, PayPal, Pluxee, Revolut, Satispay, Scalapay, Stripe, SumUp.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Laravel Pay! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Spanu18](https://github.com/Spanu18)
- [All Contributors](../../contributors)
- [All Contributors](../../contributors)

## License

Laravel Pay is open-sourced software licensed under the [MIT license](LICENSE.md).
