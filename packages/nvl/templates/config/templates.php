<?php

declare(strict_types=1);

use Nvl\Templates\Rendering\BladeTemplateRenderer;
use Nvl\Templates\Rendering\MpdfTemplateRenderer;
use Nvl\Templates\Services\ConfiguredTemplateAuthorization;

return [
    'connection' => null,

    'tables' => [
        'templates' => 'templates',
        'templates_i18n' => 'templates_i18n',
        'template_versions' => 'template_versions',
        'template_assignments' => 'template_assignments',
        'template_renders' => 'template_renders',
    ],

    'migrations' => [
        'enabled' => true,
    ],

    'adoption' => [
        'maximum_manifest_bytes' => 1_048_576,
        'maximum_records' => 10_000,
    ],

    'authorization' => [
        'class' => ConfiguredTemplateAuthorization::class,
    ],

    'routes' => [
        'management' => [
            'enabled' => false,
            'prefix' => 'api/v1/templates',
            'name' => 'nvl.templates.management.',
            'middleware' => ['api', 'auth', 'throttle:60,1'],
        ],
        'render' => [
            'enabled' => false,
            'prefix' => 'api/v1/templates/render',
            'name' => 'nvl.templates.render.',
            'middleware' => ['api', 'auth', 'throttle:60,1'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Source-controlled stored-template definitions
    |--------------------------------------------------------------------------
    |
    | These definitions drive the database implementation. The public
    | Nvl\Templates\Template class can also be rendered directly without rows.
    |
    */
    'definitions' => [],
    'owners' => [],

    /*
    |--------------------------------------------------------------------------
    | Template defaults and renderer implementations
    |--------------------------------------------------------------------------
    |
    | A Template may override these defaults through TemplateOptions. Custom
    | renderer aliases must implement the TemplateRenderer contract.
    |
    */
    'default_renderer' => 'blade',
    'default_locale' => null,
    'renderers' => [
        'blade' => BladeTemplateRenderer::class,
        'pdf' => MpdfTemplateRenderer::class,
    ],

    'limits' => [
        'schema_bytes' => 262_144,
        'schema_depth' => 16,
        'schema_items' => 2_000,
        'data_bytes' => 1_048_576,
        'data_depth' => 24,
        'data_items' => 10_000,
        'renderer_options_bytes' => 65_536,
        'renderer_options_depth' => 12,
        'renderer_options_items' => 1_000,
        'payload_bytes' => 262_144,
        'payload_depth' => 16,
        'payload_items' => 2_000,
        'settings_bytes' => 65_536,
        'metadata_bytes' => 65_536,
        'per_page' => 25,
        'maximum_per_page' => 100,
        'output_bytes' => 25_165_824,
    ],

    'views' => [
        'namespace' => 'nvl-templates',
        'defaults' => [
            'blade' => 'nvl-templates::html.document',
            'pdf' => 'nvl-templates::pdf.document',
        ],
        'publish_path' => resource_path('views/vendor/nvl-templates'),
        'allowed_publish_roots' => [resource_path('views')],
    ],

    'rendering' => [
        'queue' => null,
        'connection' => null,
        'tries' => 3,
        'timeout' => 60,
        'lease_seconds' => 75,
        'unique_for' => 600,
        'pending_recovery_seconds' => 660,
        'backoff' => [10, 30, 90],
        'recovery_batch_size' => 100,
        'store_payload' => true,
        'output' => [
            'persist' => true,
            'disk' => 'local',
            'filename_prefix' => 'template-render',
            'allowed_local_roots' => [storage_path()],
        ],
    ],

    'compatibility' => [
        'assets' => [
            'allowed_local_roots' => [resource_path(), storage_path('app')],
            'maximum_bytes' => 5_242_880,
            'maximum_inline_bytes' => 2_097_152,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Class-template asset aliases
    |--------------------------------------------------------------------------
    |
    | The null driver preserves the dependency-free default. The media driver
    | resolves explicit aliases through NVL Media using safe URLs or local paths.
    |
    */
    'assets' => [
        'driver' => 'null',
        'media' => [
            'aliases' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bundled PDF renderer
    |--------------------------------------------------------------------------
    |
    | PDF definitions use the source-controlled `renderer_options` array.
    | Remote assets fail closed and require an exact host allowlist.
    |
    */
    'pdf' => [
        'temp_path' => storage_path('framework/cache/nvl-templates/mpdf'),
        'allowed_temp_roots' => [storage_path()],
        'maximum_html_bytes' => 1_048_576,
        'remote_assets' => [
            'enabled' => false,
            'allow_http' => false,
            'allowed_hosts' => [],
        ],
        'data_images' => [
            'enabled' => true,
            'maximum_bytes' => 2_097_152,
        ],
        'allow_debug_image_errors' => false,
        'defaults' => [
            'page_size' => 'A4',
            'orientation' => 'portrait',
            'margins' => [
                'left' => 15,
                'right' => 15,
                'top' => 16,
                'bottom' => 16,
                'header' => 8,
                'footer' => 8,
            ],
            'default_font' => 'dejavusans',
            'default_font_size' => 10,
            'dpi' => 96,
            'image_dpi' => 96,
            'image_quality' => 85,
            'show_image_errors' => false,
            'author' => '',
            'creator' => 'NVL Templates',
            'keywords' => '',
            'header_view' => null,
            'footer_view' => null,
            'watermark' => null,
            'watermark_opacity' => 0.1,
            'compress' => true,
            'pdfa' => false,
            'pdfa_auto' => false,
        ],
    ],
];
