<?php

declare(strict_types=1);

namespace Nvl\Seo\Exceptions;

use Throwable;

/**
 * Raised when a profile mutation targets a stale revision.
 */
final class StaleSeoProfileException extends SeoException
{
    private ?string $profileId = null;

    /**
     * Create a stale-write exception for one profile.
     */
    public static function forProfile(string $id, ?Throwable $previous = null): self
    {
        $exception = new self(
            "SEO profile [{$id}] changed after it was read; reload it before saving.",
            $previous,
        );
        $exception->profileId = $id;

        return $exception;
    }

    /**
     * Return the stable machine-readable error code.
     */
    protected function responseCode(): string
    {
        return 'stale_seo_profile';
    }

    /**
     * Return the conflict HTTP status.
     */
    protected function status(): int
    {
        return 409;
    }

    /**
     * Return safe conflict context for API consumers.
     *
     * @return array<string, mixed>
     */
    protected function publicContext(): array
    {
        return $this->profileId === null ? [] : ['profileId' => $this->profileId];
    }
}
