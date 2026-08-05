<?php

declare(strict_types=1);

namespace Nvl\Content\Exceptions;

use Nvl\Content\Enums\ContentResponseCode;

/**
 * Optimistic concurrency conflict.
 */
final class StaleContentException extends ContentException
{
    public static function forRevision(string $resourceId, int $expected, int $actual): self
    {
        return new self(
            message: "Content resource [{$resourceId}] changed from revision {$expected} to {$actual}.",
            responseCode: ContentResponseCode::StaleContent,
            suggestedStatus: 409,
            publicContext: [
                'resource_id' => $resourceId,
                'expected_revision' => $expected,
                'actual_revision' => $actual,
            ],
        );
    }
}
