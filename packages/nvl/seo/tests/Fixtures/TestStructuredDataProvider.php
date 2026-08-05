<?php

declare(strict_types=1);

namespace Nvl\Seo\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\Seo\Contracts\StructuredDataProvider;
use Nvl\Seo\Data\StructuredDataContextData;
use Nvl\Seo\Data\StructuredDataNodeData;

/**
 * Exercises configured resource-provider registration in package tests.
 */
final class TestStructuredDataProvider implements StructuredDataProvider
{
    /**
     * Return one generic resource node linked to the resolved page.
     *
     * @return iterable<StructuredDataNodeData>
     */
    public function provide(
        Model $resource,
        StructuredDataContextData $context,
    ): iterable {
        yield StructuredDataNodeData::make(
            type: 'Thing',
            id: $context->canonicalUrl.'#resource',
            properties: [
                'name' => $resource->getAttribute('name'),
                'url' => $context->canonicalUrl,
                'mainEntityOfPage' => [
                    '@id' => $context->canonicalUrl.'#webpage',
                ],
            ],
        );
    }
}
