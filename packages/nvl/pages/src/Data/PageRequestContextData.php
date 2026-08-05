<?php

declare(strict_types=1);

namespace Nvl\Pages\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Validated site and content-locale context for one public page request.
 */
#[Hidden]
final class PageRequestContextData extends Data
{
    /**
     * Create trusted public site and locale context.
     */
    public function __construct(
        public readonly string $site,
        public readonly string $locale,
    ) {}
}
