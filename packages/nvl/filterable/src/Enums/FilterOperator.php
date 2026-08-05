<?php

declare(strict_types=1);

namespace Nvl\Filterable\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Operators accepted by the generic allowlisted filter scope.
 */
#[TypeScript]
enum FilterOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case Contains = 'contains';
    case NotContains = 'not_contains';
    case Before = 'before';
    case After = 'after';
    case Between = 'between';
    case Gt = 'gt';
    case Lt = 'lt';
    case Gte = 'gte';
    case Lte = 'lte';
    case In = 'in';
    case NotIn = 'not_in';
    case IsNull = 'is_null';
    case IsNotNull = 'is_not_null';
}
