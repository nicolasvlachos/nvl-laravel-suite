<?php

declare(strict_types=1);

namespace Nvl\Forms\Services;

use Nvl\Forms\Exceptions\FormSubmissionRejectionException;
use Nvl\Forms\Models\Form;

/**
 * Derives a non-reversible identity used to enforce one registration per client.
 */
final class FormRegistrationFingerprint
{
    public function resolve(Form $form, ?string $email, ?string $sessionId): ?string
    {
        if ($form->allowsMultipleRegistrations()) {
            return null;
        }

        $normalizedEmail = is_string($email) ? mb_strtolower(trim($email)) : '';
        if ($normalizedEmail !== '') {
            return hash('sha256', 'email:'.$normalizedEmail);
        }

        $normalizedSessionId = is_string($sessionId) ? trim($sessionId) : '';
        if ($normalizedSessionId !== '') {
            return hash('sha256', 'session:'.$normalizedSessionId);
        }

        throw new FormSubmissionRejectionException(
            message: (string) trans('forms::forms/messages.error.registration_identity_required'),
            statusCode: 422,
        );
    }
}
