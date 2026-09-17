<?php

declare(strict_types=1);

use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Translations\Actions\Entries\ListTranslationEntriesAction;
use Nvl\Translations\Services\SourceTranslationWorkspace;

test('tenant cannot browse the source synchronization workspace', function (): void {
    $tenant = new TenantId('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    $context = new class($tenant) implements TenantContext
    {
        public function __construct(private readonly TenantId $tenant) {}

        public function snapshot(): TenantContextSnapshot
        {
            return new TenantContextSnapshot(TenantContextMode::Tenant, $this->tenant);
        }

        public function requireTenant(): TenantId
        {
            return $this->tenant;
        }
    };
    $action = new ListTranslationEntriesAction(new SourceTranslationWorkspace($context));

    expect(fn () => $action->execute())->toThrow(TenantBoundaryViolation::class);
});
