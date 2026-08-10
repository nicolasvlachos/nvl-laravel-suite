<?php

declare(strict_types=1);

namespace Nvl\Templates\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One stable template alias pointing at an exact NVL Media resource.
 */
#[TypeScript]
final class MediaTemplateAssetData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $mediaId,
        public readonly string $scope = 'default',
        public readonly string $type = 'image',
        public readonly string $variation = '',
        public readonly string $delivery = 'path',
        public readonly ?int $expectedRevision = null,
    ) {}
}
