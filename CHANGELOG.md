# Release Notes

## [Unreleased](https://github.com/Spanu18/laravel-pay/compare/v0.1.0...1.x)


## [v0.1.0](https://github.com/Spanu18/laravel-pay/compare/...v0.1.0) - 2026-06-09

Initial pre-release.

### Added

- Unified `PaymentGatewayInterface` contract across all supported gateways, with `PaymentRequest`, `PaymentResult`, `CheckoutResult`, `TransactionResult`, `MerchantAccount` and `PayerContact` value objects.
- Gateway integrations: Adyen, Axerve, Binance Pay, Braintree, Coinbase, Edenred, Klarna, Mollie, Nexi, PayPal, Pluxee, Revolut, Satispay, Scalapay, Stripe and SumUp.
- `CheckoutUrls` contract with a default implementation, plus a `HostedCheckout`-enabled SumUp flow.
- Laravel service provider auto-registration with a single publishable `config/laravel-pay.php` and `laravel-pay-*` publish tags.
- Pest test coverage across DTOs and every gateway via Testbench, with PHPStan and Pint validation.
