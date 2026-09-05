<?php

declare(strict_types=1);

return [

    'placeholder' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Payment Gateways
    |--------------------------------------------------------------------------
    |
    | Configuration for the supported payment gateways. Each gateway's
    | credentials can be provided via environment variables.
    |
    */

    'payments' => [
        'sumup' => [
            'api_key' => env('SUMUP_API_KEY'),
        ],
        'nexi' => [
            'sandbox' => env('NEXI_SANDBOX', true),
        ],
        'klarna' => [
            'playground' => env('KLARNA_PLAYGROUND', true),
            'region' => env('KLARNA_REGION', 'eu'),
        ],
        'adyen' => [
            'live' => env('ADYEN_LIVE', false),
        ],
        'axerve' => [
            'sandbox' => env('AXERVE_SANDBOX', true),
        ],
        'braintree' => [
            'client_id' => env('BRAINTREE_CLIENT_ID'),
            'client_secret' => env('BRAINTREE_CLIENT_SECRET'),
            'sandbox' => env('BRAINTREE_SANDBOX', true),
        ],
        'coinbase' => [
            'api_version' => env('COINBASE_API_VERSION', '2018-03-22'),
        ],
        'mollie' => [
            'client_id' => env('MOLLIE_CLIENT_ID'),
            'client_secret' => env('MOLLIE_CLIENT_SECRET'),
        ],
        'paypal' => [
            'sandbox' => env('PAYPAL_SANDBOX', true),
        ],
        'satispay' => [
            'sandbox' => env('SATISPAY_SANDBOX', true),
        ],
        'scalapay' => [
            'sandbox' => env('SCALAPAY_SANDBOX', true),
        ],
        'stripe' => [
            'secret_key' => env('STRIPE_SECRET_KEY'),
        ],
    ],

];
