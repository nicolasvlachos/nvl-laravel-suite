<?php

declare(strict_types=1);

namespace Nvl\Templates\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves an allowlisted assignment owner alias and string-compatible identifier.
 */
interface TemplateOwnerResolver
{
    public function alias(): string;

    public function resolve(string $identifier): ?Model;
}
