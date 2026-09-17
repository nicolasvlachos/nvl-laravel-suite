<?php

declare(strict_types=1);

namespace Nvl\Templates\Data\Mutations;

/** Scalar-only request for copying one granted platform Template graph. */
final readonly class ImportPlatformTemplateData
{
    /** @param array<string,string> $mediaGrantIds */
    public function __construct(
        public string $grantId,
        public int $expectedGrantRevision,
        public int $expectedSourceRevision,
        public string $targetKey,
        public string $idempotencyKey,
        public array $mediaGrantIds,
    ) {}
}
