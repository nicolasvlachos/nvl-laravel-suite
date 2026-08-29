<?php

declare(strict_types=1);

use Nvl\Seo\Services\DirectSeoImageResolver;
use Nvl\Seo\Services\FilesystemSitemapArtifactStore;

return [
    'site' => [
        'scope' => env('SEO_SITE_SCOPE', 'default'),
        'name' => env('SEO_SITE_NAME', env('APP_NAME', 'Laravel')),
        'title_separator' => ' | ',
        'title_position' => 'suffix',
        'base_url' => env('SEO_BASE_URL', env('APP_URL', 'http://localhost')),
        'default_image_url' => env('SEO_DEFAULT_IMAGE_URL'),
        'open_graph_type' => 'website',
        'twitter_site' => null,
        'twitter_creator' => null,
    ],

    'defaults' => [
        'title' => null,
        'description' => null,
        'robots' => [
            'index' => true,
            'follow' => true,
            'max_snippet' => null,
            'max_image_preview' => 'large',
            'max_video_preview' => null,
        ],
        'twitter_card' => 'summary_large_image',
    ],

    'image_resolver' => DirectSeoImageResolver::class,

    'routes' => [
        'enabled' => false,
        'middleware' => ['web'],
        'name' => 'nvl.seo.',
        'sitemap_path' => 'sitemap.xml',
        'sitemap_chunk_path' => 'sitemap-{chunk}.xml',
        'sitemap_scopes' => [],
        'robots_path' => 'robots.txt',
    ],

    'management' => [
        'enabled' => false,
        'path' => 'api/v1/seo',
        'name' => 'nvl.seo.management.',
        'middleware' => ['api', 'auth', 'throttle:60,1'],
    ],

    'authorization' => [
        'ability' => null,
    ],

    /*
    | Stable aliases used by management/import boundaries and owner-centric
    | profile read Actions. Model relations, metadata rendering, and profile
    | mutation Actions do not require registry membership.
    */
    'owners' => [],

    'migrations' => [
        'enabled' => true,
    ],

    'sitemap' => [
        'cache_seconds' => 3600,
        'cache_key' => 'nvl-seo:sitemap',
        'artifact_store' => FilesystemSitemapArtifactStore::class,
        'disk' => null,
        'directory' => 'nvl-seo/sitemaps',
        'sources' => [],
        'max_urls' => 50000,
        'max_bytes' => 52_428_800,
        'index_enabled' => true,
        'lock_seconds' => 60,
        'lock_wait_seconds' => 10,
        'enforce_same_origin' => true,
        'enforce_path_scope' => true,
    ],

    'structured_data' => [
        /*
        | persisted: localized editor data only
        | generated: registered resource providers and baseline nodes only
        | merge: generated nodes followed by persisted overrides (recommended)
        */
        'mode' => 'merge',
        'automatic_web_site' => true,
        'automatic_web_page' => true,
        /*
        | Each entry is an array with resource, provider, and optional key/priority.
        | Both classes must be autoloadable and provider must implement the contract.
        */
        'providers' => [],
        'maximum_bytes' => 262_144,
        'maximum_depth' => 16,
        'maximum_items' => 1_000,
    ],

    'redirects' => [
        'enabled' => true,
        'maximum_chain_length' => 20,
        'record_hits' => true,
    ],

    'robots' => [
        'user_agent' => '*',
        'allow' => ['/'],
        'disallow' => [],
        'include_sitemap' => true,
        'cache_seconds' => 3600,
        'maximum_bytes' => 512_000,
    ],
];
