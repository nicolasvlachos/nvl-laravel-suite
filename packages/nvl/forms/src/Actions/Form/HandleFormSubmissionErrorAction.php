<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\Form;

use Exception;
use Illuminate\Contracts\Foundation\Application;

/**
 * Handles form submission error messages and formatting.
 * This action centralizes the complex error message logic
 * for form submission failures.
 */
final readonly class HandleFormSubmissionErrorAction
{
    public function __construct(private Application $application) {}

    /**
     * Execute error message handling.
     *
     * @param  Exception  $exception  Original exception
     * @return string Appropriate error message
     */
    public function execute(Exception $exception): string
    {
        return match (true) {
            str_contains($exception->getMessage(), 'not allowed from this host') => (string) trans('forms::forms/messages.error.host_not_allowed'),
            str_contains($exception->getMessage(), 'Required fields missing') => $exception->getMessage(),
            str_contains($exception->getMessage(), 'origin required') => (string) trans('forms::forms/messages.error.origin_required'),
            default => $this->application->environment('local')
                ? $exception->getMessage()
                : (string) trans('forms::forms/messages.error.submission_failed'),
        };
    }
}
