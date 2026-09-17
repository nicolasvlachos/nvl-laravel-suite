<?php

declare(strict_types=1);

use Nvl\Content\Contracts\ContentCatalogCopyAccess;
use Nvl\Content\Services\ContentCatalogCopyRegistry;
use Nvl\Templates\Data\Mutations\ImportPlatformTemplateData;
use Nvl\Templates\Models\TemplateVersion;
use Nvl\Templates\Services\TemplateContentCopyAccess;

it('keeps the catalog import request scalar and source keyed', function (): void {
    $request = new ImportPlatformTemplateData(
        grantId: '01995b8b-07ac-7338-bb23-41f09c13d101',
        expectedGrantRevision: 4,
        expectedSourceRevision: 9,
        targetKey: 'invoice',
        idempotencyKey: 'copy-invoice-1',
        mediaGrantIds: [
            '01995b8b-07ac-7338-bb23-41f09c13d102' => '01995b8b-07ac-7338-bb23-41f09c13d103',
        ],
    );

    expect($request->targetKey)->toBe('invoice')
        ->and($request->idempotencyKey)->toBe('copy-invoice-1')
        ->and($request->mediaGrantIds)->toHaveCount(1);
});

it('resolves a fresh template-owned content copy authorizer by alias', function (): void {
    $registry = app(ContentCatalogCopyRegistry::class);
    $first = $registry->resolve(TemplateVersion::CONTENT_OWNER_TYPE);
    $second = $registry->resolve(TemplateVersion::CONTENT_OWNER_TYPE);

    expect($first)->toBeInstanceOf(ContentCatalogCopyAccess::class)
        ->and($first)->toBeInstanceOf(TemplateContentCopyAccess::class)
        ->and($second)->not->toBe($first);
});
