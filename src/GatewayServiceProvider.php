<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use LaravelPay\LaravelPay\Contracts\CheckoutUrls;
use LaravelPay\LaravelPay\Gateways\Adyen;
use LaravelPay\LaravelPay\Gateways\Axerve;
use LaravelPay\LaravelPay\Gateways\BinancePay;
use LaravelPay\LaravelPay\Gateways\Braintree;
use LaravelPay\LaravelPay\Gateways\Coinbase;
use LaravelPay\LaravelPay\Gateways\Edenred;
use LaravelPay\LaravelPay\Gateways\Klarna;
use LaravelPay\LaravelPay\Gateways\Mollie;
use LaravelPay\LaravelPay\Gateways\Nexi;
use LaravelPay\LaravelPay\Gateways\PayPal;
use LaravelPay\LaravelPay\Gateways\Pluxee;
use LaravelPay\LaravelPay\Gateways\Revolut;
use LaravelPay\LaravelPay\Gateways\Satispay;
use LaravelPay\LaravelPay\Gateways\Scalapay;
use LaravelPay\LaravelPay\Gateways\Stripe;
use LaravelPay\LaravelPay\Gateways\Sumup;
use LaravelPay\LaravelPay\Support\DefaultCheckoutUrls;

class GatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Default CheckoutUrls contract binding — consumers can override it
        // by binding their own implementation in their own service provider.
        $this->app->singleton(CheckoutUrls::class, fn () => new DefaultCheckoutUrls);
        $this->app->bind(Nexi::class, fn ($app) => new Nexi(
            sandbox: (bool) config('laravel-pay.payments.nexi.sandbox', true),
            logger: Log::channel('nexi'),
            checkoutUrls: $app->make(CheckoutUrls::class),
        ));

        $this->app->bind(Klarna::class, fn ($app) => new Klarna(
            playground: (bool) config('laravel-pay.payments.klarna.playground', true),
            defaultRegion: (string) config('laravel-pay.payments.klarna.region', 'eu'),
            logger: Log::channel('klarna'),
            checkoutUrls: $app->make(CheckoutUrls::class),
        ));

        $this->app->bind(Sumup::class, fn ($app) => new Sumup(
            apiKey: (string) config('laravel-pay.payments.sumup.api_key'),
            logger: Log::channel('sumup'),
        ));

        $this->app->bind(Adyen::class, fn ($app) => new Adyen(
            live: (bool) config('laravel-pay.payments.adyen.live', false),
            logger: Log::channel('adyen'),
            checkoutUrls: $app->make(CheckoutUrls::class),
        ));

        $this->app->bind(Axerve::class, fn ($app) => new Axerve(
            sandbox: (bool) config('laravel-pay.payments.axerve.sandbox', true),
            logger: Log::channel('axerve'),
        ));

        $this->app->bind(Braintree::class, fn ($app) => new Braintree(
            clientId: (string) config('laravel-pay.payments.braintree.client_id'),
            clientSecret: (string) config('laravel-pay.payments.braintree.client_secret'),
            sandbox: (bool) config('laravel-pay.payments.braintree.sandbox', true),
            logger: Log::channel('braintree'),
        ));

        $this->app->bind(Coinbase::class, fn ($app) => new Coinbase(
            apiVersion: (string) config('laravel-pay.payments.coinbase.api_version', '2018-03-22'),
            logger: Log::channel('coinbase'),
            checkoutUrls: $app->make(CheckoutUrls::class),
        ));

        $this->app->bind(BinancePay::class, fn ($app) => new BinancePay(
            logger: Log::channel('binance'),
            checkoutUrls: $app->make(CheckoutUrls::class),
        ));

        $this->app->bind(Edenred::class, fn ($app) => new Edenred(
            logger: Log::channel('edenred'),
            checkoutUrls: $app->make(CheckoutUrls::class),
        ));

        $this->app->bind(Pluxee::class, fn ($app) => new Pluxee(
            logger: Log::channel('pluxee'),
            checkoutUrls: $app->make(CheckoutUrls::class),
        ));

        $this->app->bind(PayPal::class, fn ($app) => new PayPal(
            sandbox: (bool) config('laravel-pay.payments.paypal.sandbox', true),
            logger: Log::channel('paypal'),
            checkoutUrls: $app->make(CheckoutUrls::class),
        ));

        $this->app->bind(Satispay::class, fn ($app) => new Satispay(
            sandbox: (bool) config('laravel-pay.payments.satispay.sandbox', true),
            logger: Log::channel('satispay'),
            checkoutUrls: $app->make(CheckoutUrls::class),
        ));

        $this->app->bind(Mollie::class, fn ($app) => new Mollie(
            clientId: (string) config('laravel-pay.payments.mollie.client_id'),
            clientSecret: (string) config('laravel-pay.payments.mollie.client_secret'),
            logger: Log::channel('mollie'),
            checkoutUrls: $app->make(CheckoutUrls::class),
        ));

        $this->app->bind(Revolut::class, fn ($app) => new Revolut(
            logger: Log::channel('revolut'),
        ));

        $this->app->bind(Scalapay::class, fn ($app) => new Scalapay(
            sandbox: (bool) config('laravel-pay.payments.scalapay.sandbox', true),
            logger: Log::channel('scalapay'),
            checkoutUrls: $app->make(CheckoutUrls::class),
        ));

        $this->app->bind(Stripe::class, fn ($app) => new Stripe(
            platformSecretKey: config('laravel-pay.payments.stripe.secret_key'),
            logger: Log::channel('stripe'),
            checkoutUrls: $app->make(CheckoutUrls::class),
        ));
    }
}
