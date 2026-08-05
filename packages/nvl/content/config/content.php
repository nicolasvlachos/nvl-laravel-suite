<?php

declare(strict_types=1);

use Nvl\Content\Services\ConfiguredContentAuthorization;

return [
    'connection' => null,

    'tables' => [
        'definitions' => 'content_definitions',
        'blocks' => 'content_blocks',
        'blocks_i18n' => 'content_blocks_i18n',
        'placements' => 'content_placements',
        'revisions' => 'content_revisions',
    ],

    'migrations' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Stored definition value migrations
    |--------------------------------------------------------------------------
    |
    | Register deterministic ContentDefinitionMigration classes in sequential
    | one-version steps. Each command invocation applies one atomic batch.
    |
    */
    'definition_migrations' => [],
    'definition_sync' => [
        'lock_seconds' => 60,
        'lock_wait_seconds' => 10,
    ],
    'definition_migration' => [
        'batch_size' => 100,
        'maximum_batch_size' => 1_000,
        'transaction_attempts' => 3,
    ],

    'authorization' => [
        'class' => ConfiguredContentAuthorization::class,
        'callback' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Source-controlled block definitions
    |--------------------------------------------------------------------------
    |
    | Inline definitions and *.content.php / *.content.json files share the
    | same shape. Directories are scanned deterministically and files remain
    | authoritative; database rows are a queryable synchronization mirror.
    |
    */
    'definitions' => [],
    'definition_paths' => [
        resource_path('content'),
    ],
    'required_definition_paths' => [],
    'definition_limits' => [
        'maximum_files' => 500,
        'maximum_file_bytes' => 1_048_576,
    ],
    'allowed_definition_roots' => [
        base_path(),
    ],

    'scopes' => [
        'global' => [
            'key_pattern' => '/^(?:\*|[A-Za-z0-9][A-Za-z0-9_.:-]{0,190})$/',
        ],
    ],
    'owners' => [],
    'references' => [],
    'field_types' => [],
    'presets' => [],

    'links' => [
        'allowed_schemes' => ['https', 'mailto', 'tel'],
        'allow_relative' => true,
    ],

    'locales' => [
        'available' => [],
        'required_on_publish' => [],
    ],

    'validation' => [
        'maximum_payload_bytes' => 524_288,
        'maximum_metadata_bytes' => 65_536,
        'maximum_reference_display_bytes' => 65_536,
        'maximum_snapshot_bytes' => 2_097_152,
        'maximum_snapshot_depth' => 32,
        'maximum_revision_bytes' => 2_097_152,
        'maximum_fields' => 250,
        'maximum_depth' => 12,
        'maximum_items' => 500,
        'maximum_string_length' => 100_000,
        'reject_unknown_fields' => true,
        'json_schema' => [
            'allow_remote_references' => false,
        ],
        'url_schemes' => ['https'],
    ],

    'rich_text' => [
        'maximum_input_length' => 250_000,
        'allowed_link_schemes' => ['https', 'mailto', 'tel'],
        'allow_relative_links' => true,
    ],

    'media' => [
        'require_public_for_public_blocks' => true,
        'allow_private_for_private_blocks' => true,
        'maximum_per_field' => 50,
        'private_url_ttl_minutes' => 15,
    ],

    'placements' => [
        'maximum_per_group' => 1_000,
        'maximum_depth' => 50,
        'lock_seconds' => 30,
        'lock_wait_seconds' => 10,
    ],

    'rendering' => [
        'default_view' => 'nvl-content::blocks.default',
        'strict_views' => true,
    ],

    'view_publishing' => [
        'allowed_roots' => [
            resource_path('views'),
        ],
    ],

    'routes' => [
        'management' => [
            'enabled' => false,
            'prefix' => 'api/v1/content',
            'name' => 'nvl.content.management.',
            'middleware' => ['api', 'auth'],
        ],
        'public' => [
            'enabled' => false,
            'prefix' => 'api/v1/content',
            'name' => 'nvl.content.public.',
            'middleware' => ['api', 'throttle:120,1'],
        ],
    ],
];
