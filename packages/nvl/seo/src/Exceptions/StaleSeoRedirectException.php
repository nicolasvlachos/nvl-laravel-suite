<?php

declare(strict_types=1);

namespace Nvl\Seo\Exceptions;

use Throwable;

/**
 * Raised when a redirect mutation targets an outdated revision.
 */
final class StaleSeoRedirectException extends SeoException
{
    private ?string $redirectId = null;

    /**
     * Create a stale-write exception for one redirect.
     */
    public static function forRedirect(string $id, ?Throwable $previous = null): self
    {
        $exception = new self(
            "SEO redirect [{$id}] changed after it was read. Refresh it before retrying.",
            $previous,
        );
        $exception->redirectId = $id;

        return $exception;
    }

    /**
     * Return the stable machine-readable error code.
     */
    protected function responseCode(): string
    {
        return 'stale_seo_redirect';
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
        return $this->redirectId === null ? [] : ['redirectId' => $this->redirectId];
    }
}
