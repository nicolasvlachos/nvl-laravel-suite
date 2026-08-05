<?php

declare(strict_types=1);

namespace Nvl\Forms\Support;

/**
 * CustomFormGuardResult carries normalized metadata for safe custom handler execution.
 */
final readonly class CustomFormGuardResult
{
    /**
     * @param  array<string, mixed>  $handlerPayload
     */
    public function __construct(
        public ?string $submittedFrom,
        public string $ipAddress,
        public ?string $userAgent,
        public ?string $sessionId,
        public array $handlerPayload,
    ) {}
}
