<?php

declare(strict_types=1);

namespace Nvl\Content\Tests\Fixtures;

use InvalidArgumentException;
use Nvl\Content\FieldTypes\AbstractFieldTypeAdapter;
use Nvl\Content\Schema\ContentFieldDefinition;
use Nvl\Content\Validation\ContentValidationContext;

/**
 * Demonstrates publication-sensitive normalization supplied by a consumer.
 */
final class PublishingTextFieldAdapter extends AbstractFieldTypeAdapter
{
    public function alias(): string
    {
        return 'publishing_text';
    }

    public function normalize(mixed $value, ContentFieldDefinition $field, ContentValidationContext $context): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Publishing text requires a string.');
        }

        return $context->publishing ? 'Published: '.$value : $value;
    }
}
