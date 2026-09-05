<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay;

use Illuminate\Support\ServiceProvider;
use LaravelPay\LaravelPay\Console\Commands\LaravelPayCommand;

class LaravelPayServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-pay.php', 'laravel-pay');

        $this->app->singleton(LaravelPay::class);

        // Gateway bindings — consumers can override CheckoutUrls and/or
        // configure the individual gateways via the package config.
        $this->app->register(GatewayServiceProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/laravel-pay.php');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'laravel-pay');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'laravel-pay');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/laravel-pay.php' => config_path('laravel-pay.php'),
        ], ['laravel-pay', 'laravel-pay-config']);

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/laravel-pay'),
        ], ['laravel-pay', 'laravel-pay-views']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/laravel-pay'),
        ], ['laravel-pay', 'laravel-pay-lang']);

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/laravel-pay'),
        ], ['laravel-pay', 'laravel-pay-assets']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['laravel-pay', 'laravel-pay-migrations']);

        $this->commands([
            LaravelPayCommand::class,
        ]);
    }
}
