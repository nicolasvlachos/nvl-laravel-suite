<?php

declare(strict_types=1);

namespace Nvl\Templates\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Immutable renderer output suitable for responses, mail bodies, or Media ingestion.
 */
#[TypeScript]
final class RenderedTemplateData extends Data
{
    use DataTransform;

    public function __construct(
        public readonly string $content,
        public readonly string $mimeType,
        public readonly string $renderer,
        public readonly int $byteSize,
        public readonly string $checksum,
        public readonly ?string $subject = null,
        public readonly ?string $suggestedFilename = null,
    ) {}
}
