<?php

declare(strict_types=1);

namespace Nvl\Seo\Exceptions;

use Throwable;

/**
 * Raised when a typed SEO mutation violates a domain invariant.
 */
final class InvalidSeoMutationException extends SeoException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    private function __construct(
        string $message,
        private readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $previous);
    }

    /**
     * Create an invariant failure with optional field errors.
     *
     * @param  array<string, list<string>>  $errors
     */
    public static function because(
        string $message,
        array $errors = [],
        ?Throwable $previous = null,
    ): self {
        return new self($message, $errors, $previous);
    }

    /**
     * Create an invariant failure for one input field.
     */
    public static function forField(
        string $field,
        string $message,
        ?Throwable $previous = null,
    ): self {
        return new self($message, [$field => [$message]], $previous);
    }

    /**
     * Return the stable machine-readable error code.
     */
    protected function responseCode(): string
    {
        return 'invalid_seo_mutation';
    }

    /**
     * Return the unprocessable-content HTTP status.
     */
    protected function status(): int
    {
        return 422;
    }

    /**
     * Return safe validation errors for API consumers.
     *
     * @return array<string, mixed>
     */
    protected function publicContext(): array
    {
        return $this->errors === [] ? [] : ['errors' => $this->errors];
    }
}
