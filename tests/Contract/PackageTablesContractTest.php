<?php

declare(strict_types=1);

use Nvl\Activity\Definitions\Tables\ActivityTables;
use Nvl\Auth\Definitions\Tables\AuthTables;
use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Content\Definitions\Tables\ContentTables;
use Nvl\Forms\Definitions\Tables\FormsTables;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Pages\Definitions\Tables\PagesTables;
use Nvl\Seo\Definitions\Tables\SeoTables;
use Nvl\Settings\Definitions\Tables\SettingsTables;
use Nvl\Taxonomy\Definitions\Tables\TaxonomyTables;
use Nvl\Templates\Definitions\Tables\TemplatesTables;
use Nvl\Translations\Definitions\Tables\TranslationsTables;

const PACKAGE_TABLE_CONTRACTS = [
    'activity' => [ActivityTables::class, ['ActivityLog' => 'activity_log']],
    'auth' => [AuthTables::class, [
        'Users' => 'nvl_auth_users',
        'Roles' => 'nvl_auth_roles',
        'Permissions' => 'nvl_auth_permissions',
        'ModelHasPermissions' => 'nvl_auth_model_has_permissions',
        'ModelHasRoles' => 'nvl_auth_model_has_roles',
        'RoleHasPermissions' => 'nvl_auth_role_has_permissions',
        'PersonalAccessTokens' => 'nvl_auth_personal_access_tokens',
        'PasswordResetTokens' => 'nvl_auth_password_reset_tokens',
        'Clients' => 'nvl_auth_clients',
        'ClientSessions' => 'nvl_auth_client_sessions',
        'Invitations' => 'nvl_auth_invitations',
        'Challenges' => 'nvl_auth_challenges',
        'TotpCredentials' => 'nvl_auth_totp_credentials',
        'Passkeys' => 'nvl_auth_passkeys',
        'RecoveryCodes' => 'nvl_auth_recovery_codes',
        'SocialIdentities' => 'nvl_auth_social_identities',
        'Audits' => 'nvl_auth_audits',
    ]],
    'comments' => [CommentsTables::class, [
        'Comments' => 'comments',
        'Reactions' => 'comment_reactions',
        'Revisions' => 'comment_revisions',
        'Reports' => 'comment_reports',
    ]],
    'content' => [ContentTables::class, [
        'Definitions' => 'content_definitions',
        'Blocks' => 'content_blocks',
        'BlocksI18n' => 'content_blocks_i18n',
        'Placements' => 'content_placements',
        'Revisions' => 'content_revisions',
    ]],
    'forms' => [FormsTables::class, [
        'Forms' => 'forms',
        'I18n' => 'forms_i18n',
        'Entries' => 'form_entries',
        'SubmissionReceipts' => 'form_submission_receipts',
        'AllowedOrigins' => 'form_allowed_origins',
        'Analytics' => 'form_analytics',
        'RateLimits' => 'form_rate_limits',
    ]],
    'mail-notifications' => [MailNotificationsTables::class, [
        'Notifications' => 'mail_notifications',
        'Events' => 'mail_notification_events',
        'ScheduledMessages' => 'scheduled_mail_messages',
    ]],
    'media' => [MediaTables::class, [
        'Media' => 'px_media',
        'Associations' => 'px_media_associations',
        'ImageVariations' => 'px_media_image_variations',
        'I18n' => 'px_media_i18n',
        'MultipartUploads' => 'px_media_multipart_uploads',
    ]],
    'metafields' => [MetafieldsTables::class, [
        'Metafields' => 'metafields',
        'Definitions' => 'metafields_definitions',
        'DefinitionsI18n' => 'metafields_definitions_i18n',
        'DefinitionAssignments' => 'metafield_definition_assignments',
        'I18n' => 'metafields_i18n',
    ]],
    'pages' => [PagesTables::class, [
        'Pages' => 'pages',
        'I18n' => 'pages_i18n',
        'TreeLocks' => 'page_tree_locks',
    ]],
    'seo' => [SeoTables::class, [
        'Profiles' => 'seo_profiles',
        'I18n' => 'seo_profiles_i18n',
        'Redirects' => 'seo_redirects',
    ]],
    'settings' => [SettingsTables::class, ['Settings' => 'settings']],
    'taxonomy' => [TaxonomyTables::class, [
        'Terms' => 'terms',
        'I18n' => 'terms_i18n',
        'Termables' => 'termables',
    ]],
    'templates' => [TemplatesTables::class, [
        'Templates' => 'templates',
        'I18n' => 'templates_i18n',
        'Versions' => 'template_versions',
        'Assignments' => 'template_assignments',
        'Renders' => 'template_renders',
    ]],
    'translations' => [TranslationsTables::class, [
        'Entries' => 'translation_entries',
        'ScanRuns' => 'translation_scan_runs',
        'Usages' => 'translation_usages',
    ]],
];

it('publishes typed canonical table constants for every schema-owning package', function (): void {
    $root = dirname(__DIR__, 2);
    $schemaPackages = [];

    foreach (glob($root.'/packages/nvl/*/database/migrations') ?: [] as $directory) {
        if ((glob($directory.'/*.php') ?: []) !== []) {
            $schemaPackages[] = basename(dirname($directory, 2));
        }
    }

    sort($schemaPackages);

    expect(array_keys(PACKAGE_TABLE_CONTRACTS))->toBe($schemaPackages);

    foreach (PACKAGE_TABLE_CONTRACTS as $package => [$class, $expected]) {
        $reflection = new ReflectionClass($class);

        expect($reflection->isFinal() || $class === FormsTables::class)->toBeTrue();

        foreach ($expected as $name => $table) {
            $constant = $reflection->getReflectionConstant($name);

            expect($constant)->not->toBeFalse()
                ->and($constant->isPublic())->toBeTrue()
                ->and((string) $constant->getType())->toBe('string')
                ->and($constant->getValue())->toBe($table);
        }

        expect($reflection->getFileName())->toBe(
            $root.'/packages/nvl/'.$package.'/src/Definitions/Tables/'.$reflection->getShortName().'.php',
        );
    }
});

it('keeps package database access free of literal table identifiers', function (): void {
    $root = dirname(__DIR__, 2);
    $patterns = [
        '/(?:Schema|DB)::(?:create|table|dropIfExists|hasTable)\(\s*[\'\"]/',
        '/\$(?:schema|connection)->(?:create|table|dropIfExists|hasTable)\(\s*[\'\"](?!\{\$)/',
        '/(?:protected|public)\s+\$table\s*=\s*[\'\"]/',
        '/(?:public|protected|private)\s+const(?:\s+string)?\s+TABLE(?:_NAME)?\s*=\s*[\'\"]/',
    ];
    $violations = [];

    foreach (glob($root.'/packages/nvl/*/{src,database}', GLOB_BRACE) ?: [] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            foreach ($patterns as $pattern) {
                if (is_string($source) && preg_match($pattern, $source) === 1) {
                    $violations[] = str_replace($root.'/', '', $file->getPathname());

                    break;
                }
            }
        }
    }

    expect($violations)->toBe([]);
});
