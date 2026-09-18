<?php

declare(strict_types=1);

use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Services\TenantResourceRegistry;

it('declares the complete tenant workflow ownership graph before a journey is admitted', function (): void {
    $resources = app(TenantResourceRegistry::class);
    $expected = [
        'forms.forms' => TenantResourceKind::Root,
        'forms.entries' => TenantResourceKind::Inherited,
        'templates.templates' => TenantResourceKind::Root,
        'templates.renders' => TenantResourceKind::Inherited,
        'comments.comments' => TenantResourceKind::Inherited,
        'activity.events' => TenantResourceKind::Root,
        'mail.scheduled' => TenantResourceKind::Root,
        'mail.notifications' => TenantResourceKind::Root,
        'mail.events' => TenantResourceKind::Inherited,
    ];

    foreach ($expected as $resource => $kind) {
        expect($resources->get($resource)->kind)->toBe($kind);
    }
});

it('requires explicit worklists for platform-wide workflow maintenance', function (): void {
    expect(config('activity.tenancy.active_tenant_worklist'))->toBe([])
        ->and(config('mail-notifications.tenancy.active_tenant_worklist'))->toBe([]);
});
