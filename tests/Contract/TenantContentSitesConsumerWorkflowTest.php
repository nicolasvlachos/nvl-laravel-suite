<?php

declare(strict_types=1);

it('ships a sealed tenant Content and Sites consumer with cached-boot proof', function (): void {
    $root = dirname(__DIR__, 2);
    $runner = file_get_contents($root.'/tools/run-tenant-content-sites-consumer.sh');
    $provider = file_get_contents($root.'/tools/fixtures/tenant-content-sites-consumer/app/Providers/TenantContentSitesServiceProvider.php');
    $command = file_get_contents($root.'/tools/fixtures/tenant-content-sites-consumer/app/Console/Commands/TenantContentSitesProofCommand.php');
    $workflow = file_get_contents($root.'/.github/workflows/package-release.yml');

    expect($runner)->toContain(
        'composer create-project --no-interaction --no-dev',
        'php artisan config:cache',
        'php artisan route:cache',
        'php artisan tenant-content-sites:proof',
        'composer audit --locked --no-interaction',
    )->and($provider)->toContain(
        'TenantDirectory::class',
        'TenantSiteResolver::class',
        'ContentDefinitionSource',
    )->and($command)->toContain(
        "['media', 'content', 'metafields', 'seo', 'pages']",
        'Content::capture',
        'SitemapCache::class',
        'SyncSeoRedirectAction::class',
    )->and($workflow)->toContain('tools/run-tenant-content-sites-consumer.sh');
});
