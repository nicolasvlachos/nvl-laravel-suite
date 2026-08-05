<?php

declare(strict_types=1);

namespace Nvl\Filterable\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Scalar shapes accepted by declared filters.
 */
#[TypeScript]
enum FilterValueType: string
{
    case Boolean = 'boolean';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case String = 'string';
    case Enum = 'enum';
    case Date = 'date';
    case DateTime = 'date_time';
}
