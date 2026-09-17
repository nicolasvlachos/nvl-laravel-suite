<?php

declare(strict_types=1);

namespace Nvl\Content\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Content\Validation\ContentValidationContext;

/** Supplies the canonical model whose ownership gates a dynamic Content reference. */
interface TenantSafeContentReferenceResolver extends ContentReferenceResolver
{
    public function tenantRecord(string $identifier, ContentValidationContext $context): ?Model;
}
