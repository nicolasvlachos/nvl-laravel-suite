<?php

declare(strict_types=1);

namespace Nvl\Forms\Services;

use Nvl\Forms\Models\Form;
use Nvl\Forms\Results\FormSubmissionResult;
use Nvl\Forms\Support\FormErrorMapperRegistry;
use Nvl\Support\Exceptions\BusinessException;

/**
 * Maps reusable public submission warning and business-error response data.
 */
final class PublicFormSubmissionResponseMapper
{
    /**
     * @param  FormErrorMapperRegistry  $errorMapperRegistry  Registry for form-specific business error mappers
     */
    public function __construct(
        private readonly FormErrorMapperRegistry $errorMapperRegistry,
    ) {}

    /**
     * Resolve an optional warning message for degraded Forms bookkeeping.
     *
     * @param  FormSubmissionResult  $submissionResult  Submission result to inspect
     * @return string|null Warning message or null when bookkeeping succeeded
     */
    public function warning(FormSubmissionResult $submissionResult): ?string
    {
        if (! $submissionResult->hasBookkeepingWarning) {
            return null;
        }

        return (string) trans('forms::forms/messages.warning.submission_recording_delayed');
    }

    /**
     * Map a business exception to custom form errors, falling back to a top-level error.
     *
     * @param  Form  $form  Form that received the submission
     * @param  BusinessException  $exception  Exception to map
     * @return array<string, mixed> Mapped errors
     */
    public function businessErrors(Form $form, BusinessException $exception): array
    {
        $message = trim($exception->getMessage());

        if ($message === '') {
            return ['error' => (string) trans('forms::forms/messages.api.error')];
        }

        $mappedErrors = $this->errorMapperRegistry->map($form, $exception);

        if ($mappedErrors !== null) {
            return $mappedErrors;
        }

        return ['error' => $message];
    }
}
