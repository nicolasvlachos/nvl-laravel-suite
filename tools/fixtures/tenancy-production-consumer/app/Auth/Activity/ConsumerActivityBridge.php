<?php

declare(strict_types=1);

namespace App\Auth\Activity;

use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Auth\Contracts\TenantAwareAuthActivityBridge;
use Nvl\Auth\ValueObjects\AuthEventContext;

/** Projects bounded Auth audit semantics into tenant-partitioned Activity storage. */
final readonly class ConsumerActivityBridge implements TenantAwareAuthActivityBridge
{
    /** Create the bridge around Activity's canonical recorder. */
    public function __construct(private ActivityRecorder $activities) {}

    /** @param array<string, scalar|null> $metadata */
    public function record(string $action, AuthEventContext $context, array $metadata): void
    {
        $this->activities->record(
            subject: null,
            event: $action,
            context: ['tenant_id' => $context->tenantId?->value, ...$metadata],
            resolveChanges: false,
        );
    }
}
