<?php

declare(strict_types=1);

namespace Nvl\Seo\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Seo\Data\StructuredDataContextData;
use Nvl\Seo\Data\StructuredDataNodeData;

/**
 * Builds schema.org nodes for one registered Eloquent resource family.
 */
interface StructuredDataProvider
{
    /**
     * Return structured-data nodes that accurately describe the visible resource.
     *
     * @return iterable<StructuredDataNodeData>
     */
    public function provide(
        Model $resource,
        StructuredDataContextData $context,
    ): iterable;
}
