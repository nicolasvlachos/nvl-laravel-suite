<?php

declare(strict_types=1);

namespace App\Auth\Activity;

use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Auth\Contracts\TenantAwareAuthActivityBridge;
use Nvl\Auth\ValueObjects\AuthEventContext;

/** Projects the Auth bridge's bounded semantics through Activity's canonical writer. */
final readonly class AuthConsumerActivityBridge implements TenantAwareAuthActivityBridge
{
    public function __construct(private ActivityRecorder $activities) {}

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
