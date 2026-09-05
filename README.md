<div align="center">
    <h1>Laravel Pay</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/laravel-pay/laravel-pay"><img src="https://img.shields.io/packagist/v/laravel-pay/laravel-pay.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/laravel-pay/laravel-pay"><img src="https://img.shields.io/packagist/php-v/laravel-pay/laravel-pay.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/laravel-pay/laravel-pay"><img src="https://badge.laravel.cloud/badge/laravel-pay/laravel-pay?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/laravel-pay/laravel-pay/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/laravel-pay/laravel-pay/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/laravel-pay/laravel-pay"><img src="https://img.shields.io/packagist/dt/laravel-pay/laravel-pay.svg?style=flat-square" alt="Total Downloads"></a>
</p>

This is laravel pay!

## Installation

You can install the package via Composer:

```bash
composer require laravel-pay/laravel-pay
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

<!-- Add a basic usage example here. -->

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Laravel Pay! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Spanu18](https://github.com/laravel-pay)
- [All Contributors](../../contributors)

## License

Laravel Pay is open-sourced software licensed under the [MIT license](LICENSE.md).
