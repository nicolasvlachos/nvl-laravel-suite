<?php

declare(strict_types=1);

use Nvl\Primitives\Services\ConfiguredExchangeRateProvider;

return [
    /*
    |--------------------------------------------------------------------------
    | Money
    |--------------------------------------------------------------------------
    |
    | Values are always calculated exactly. A rounding mode is required at the
    | boundary where a fractional result must become a currency amount.
    |
    */
    'money' => [
        'default_locale' => env('PRIMITIVES_DEFAULT_LOCALE', 'en'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Phone numbers
    |--------------------------------------------------------------------------
    |
    | A region is mandatory when parsing a national number. International
    | E.164 input beginning with "+" does not need a default region.
    |
    */
    'phone' => [
        'default_region' => env('PRIMITIVES_PHONE_REGION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Exchange rates
    |--------------------------------------------------------------------------
    |
    | Rates map one major unit of the source currency to the target currency.
    | Bind your own provider for live or database-backed rates.
    |
    */
    'exchange_rates' => [
        'implementation' => ConfiguredExchangeRateProvider::class,
        'rates' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Application locales
    |--------------------------------------------------------------------------
    */
    'locales' => [
        'supported' => ['en'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Application-owned reference entries
    |--------------------------------------------------------------------------
    |
    | Countries, currencies, and languages come from Symfony Intl. Cities and
    | banks are deployment-specific, so applications may configure entries or
    | bind their own directory implementations.
    |
    */
    'reference' => [
        'cities' => [],
        'banks' => [],
    ],
];
