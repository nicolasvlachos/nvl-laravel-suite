<?php

declare(strict_types=1);

namespace Nvl\Seo\Data\Import;

use Nvl\Seo\Data\Mutations\SeoProfilePayload;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Neutral owner alias plus validated profile mutation supplied by an importer.
 */
#[TypeScript]
final class SeoImportRecordData extends Data
{
    public function __construct(
        public readonly string $ownerAlias,
        public readonly string $ownerId,
        public readonly ?string $scope,
        public readonly SeoProfilePayload $profile,
    ) {}
}
