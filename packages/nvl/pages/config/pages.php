<?php

declare(strict_types=1);

use Nvl\Pages\Definitions\Tables\PagesTables;
use Nvl\Pages\Services\ConfiguredPageAuthorization;
use Nvl\Pages\Services\ConfiguredPageRequestContextResolver;
use Nvl\Pages\Services\ConfiguredPageUrlGenerator;

return [
    'connection' => null,

    'tables' => [
        'pages' => PagesTables::Pages,
        'pages_i18n' => PagesTables::I18n,
        'page_tree_locks' => PagesTables::TreeLocks,
    ],

    'migrations' => [
        'enabled' => true,
    ],

    'hierarchy' => [
        'maximum_depth' => 4,
    ],

    'transactions' => [
        'attempts' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dynamic page resources
    |--------------------------------------------------------------------------
    |
    | Register stable aliases rather than accepting handler class names from
    | requests. Each handler owns its query, conditions, route parameters,
    | transport-safe presentation, and optional sitemap stream.
    |
    */
    'resources' => [],

    'public' => [
        'default_site' => 'default',
        'context_resolver' => ConfiguredPageRequestContextResolver::class,
    ],

    'authorization' => [
        'class' => ConfiguredPageAuthorization::class,
    ],

    'urls' => [
        'generator' => ConfiguredPageUrlGenerator::class,
        'base_url' => env('PAGES_BASE_URL', env('APP_URL', 'http://localhost')),
        'locale_prefix' => false,
        'default_locale' => env('APP_LOCALE', 'en'),
    ],

    'integrations' => [
        'seo_owner_alias' => 'page',
        'metafield_owner_alias' => 'page',
        'metafield_sections' => ['general', 'navigation'],
    ],

    'routes' => [
        'public' => [
            'enabled' => false,
            'prefix' => 'api/v1/pages',
            'name' => 'nvl.pages.public.',
            'middleware' => ['api', 'throttle:120,1'],
        ],
        'management' => [
            'enabled' => false,
            'prefix' => 'api/v1/pages/_manage',
            'name' => 'nvl.pages.management.',
            'middleware' => ['api', 'auth', 'throttle:60,1'],
        ],
    ],

    'limits' => [
        'per_page' => 25,
        'maximum_per_page' => 100,
        'maximum_page_options' => 100,
        'maximum_public_children' => 100,
        'maximum_path_bytes' => 768,
        'maximum_resource_parameters' => 8,
    ],
];
