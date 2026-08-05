<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\Form;

use Nvl\Forms\Exceptions\FormSubmissionRejectionException;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Services\PublicFormTokenService;
use Nvl\Forms\Support\FormSubmissionContext;

/**
 * Validates anti-forgery protection for public form submissions.
 */
final class ValidateFormSubmissionProtectionAction
{
    /**
     * @param  PublicFormTokenService  $tokenService  Signed public-token validator
     */
    public function __construct(private PublicFormTokenService $tokenService) {}

    /**
     * Validate submission protection requirements for a form request.
     *
     * When CSRF is required for the form, the request must provide either:
     * - a valid CSRF token tied to the request session, or
     * - a valid signed public token for this form.
     *
     * @param  Form  $form  Target form
     * @param  FormSubmissionContext  $context  Request-derived submission context
     *
     * @throws FormSubmissionRejectionException When the submission fails CSRF/public-token validation
     */
    public function execute(Form $form, FormSubmissionContext $context): void
    {
        if (! $form->require_csrf) {
            return;
        }

        if ($this->hasValidCsrfToken($context)) {
            return;
        }

        $publicToken = $context->publicToken;
        if ($this->tokenService->validate($publicToken, $form)) {
            return;
        }

        throw new FormSubmissionRejectionException(
            message: (string) trans('forms::forms/messages.error.csrf_failed'),
            statusCode: 419,
        );
    }

    /**
     * Resolve and verify the CSRF token against the current session.
     */
    private function hasValidCsrfToken(FormSubmissionContext $context): bool
    {
        if ($context->sessionToken === null || $context->sessionToken === '') {
            return false;
        }

        $providedToken = $context->csrfToken;
        if ($providedToken === null || $providedToken === '') {
            return false;
        }

        return hash_equals($context->sessionToken, $providedToken);
    }
}
