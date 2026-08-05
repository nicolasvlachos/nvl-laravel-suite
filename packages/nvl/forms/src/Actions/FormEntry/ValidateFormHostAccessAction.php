<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\FormEntry;

use Nvl\Forms\Models\Form;
use Nvl\Forms\Services\FormOriginAccessService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Validates host access restrictions for a form submission.
 */
final class ValidateFormHostAccessAction
{
    /**
     * @param  FormOriginAccessService  $originAccess  Origin access resolver
     */
    public function __construct(
        private readonly FormOriginAccessService $originAccess,
    ) {}

    /**
     * Ensure the submitted origin is allowed when public access is restricted.
     *
     * @param  Form|string  $form  Form model or identifier
     * @param  string|null  $submittedFrom  Origin host that submitted the form
     *
     * @throws AccessDeniedHttpException If host is not allowed or missing when required
     */
    public function execute(Form|string $form, ?string $submittedFrom): void
    {
        $formModel = $form instanceof Form ? $form : Form::findOrFail($form);

        if (! $formModel->restrict_public_access) {
            return;
        }

        if ($submittedFrom === null || $submittedFrom === '') {
            throw new AccessDeniedHttpException((string) trans('forms::forms/messages.error.origin_required'));
        }

        if (! $this->originAccess->isOriginAllowed($formModel, $submittedFrom)) {
            throw new AccessDeniedHttpException((string) trans('forms::forms/shared.messages.error.origin_not_allowed', ['origin' => $submittedFrom]));
        }
    }
}
