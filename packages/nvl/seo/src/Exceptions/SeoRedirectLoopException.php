<?php

declare(strict_types=1);

namespace Nvl\Seo\Exceptions;

/**
 * Raised when a redirect points to itself or introduces a chain cycle.
 */
final class SeoRedirectLoopException extends SeoException
{
    /**
     * Return the stable machine-readable error code.
     */
    protected function responseCode(): string
    {
        return 'seo_redirect_loop';
    }

    /**
     * Return the conflict HTTP status.
     */
    protected function status(): int
    {
        return 409;
    }
}
