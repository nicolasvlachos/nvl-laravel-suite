<?php

declare(strict_types=1);

namespace Nvl\Translations\Services;

use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;

/** Admits source-code translation tooling only in disabled or explicit platform context. */
final readonly class SourceTranslationWorkspace
{
    public function __construct(private TenantContext $context) {}

    /** Deny before any catalog query or filesystem access. */
    public function authorize(): void
    {
        if (! in_array($this->context->snapshot()->mode, [TenantContextMode::Disabled, TenantContextMode::Platform], true)) {
            throw new TenantBoundaryViolation('Source translation tooling requires explicit platform context.');
        }
    }
}
