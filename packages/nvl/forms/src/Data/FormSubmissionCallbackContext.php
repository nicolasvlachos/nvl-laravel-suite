<?php

declare(strict_types=1);

namespace Nvl\Forms\Data;

/** Immutable request facts safe for committed tenant callbacks. */
final readonly class FormSubmissionCallbackContext
{
    /** Create scalar callback context captured inside the public request boundary. */
    public function __construct(
        public string $tenantId,
        public ?string $origin,
        public string $locale,
        public ?string $correlationId,
    ) {}
}
