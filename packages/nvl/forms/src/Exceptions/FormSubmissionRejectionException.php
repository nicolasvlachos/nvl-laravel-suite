<?php

declare(strict_types=1);

namespace Nvl\Forms\Exceptions;

/**
 * Thrown when a form submission is rejected by spam protection, honeypot, or other security rules.
 */
final class FormSubmissionRejectionException extends FormException
{
    /**
     * Create a new submission rejection exception.
     *
     * @param  string  $message  Rejection reason
     * @param  int  $statusCode  HTTP status the controller should emit
     */
    public function __construct(
        string $message,
        private readonly int $statusCode,
    ) {
        parent::__construct($message);
    }

    /**
     * Resolve the controller-facing status code for the rejection.
     */
    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
