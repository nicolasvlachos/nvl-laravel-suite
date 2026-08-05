<?php

declare(strict_types=1);

namespace Nvl\Forms\Services;

use Nvl\Forms\Models\AllowedOrigin;
use Nvl\Forms\Models\Form;

/**
 * Resolves host/origin access checks for restricted public forms.
 */
final class FormOriginAccessService
{
    /**
     * @param  OriginMatchingService  $originMatcher  Host/origin matcher
     */
    public function __construct(
        private readonly OriginMatchingService $originMatcher,
    ) {}

    /**
     * Determine whether the provided origin is allowed for the form.
     */
    public function isOriginAllowed(Form|string $form, string $origin): bool
    {
        $formModel = $form instanceof Form ? $form : Form::findOrFail($form);

        if (! $formModel->restrict_public_access) {
            return true;
        }

        return $this->resolveMatchingOrigin($formModel, $origin) instanceof AllowedOrigin;
    }

    /**
     * Find the matching active allowed-origin record for the provided origin.
     */
    public function resolveMatchingOrigin(Form|string $form, string $origin): ?AllowedOrigin
    {
        $formModel = $form instanceof Form ? $form : Form::findOrFail($form);
        $formModel->loadMissing('allowedOrigins');

        /** @var ?AllowedOrigin $matched */
        $matched = $formModel->allowedOrigins
            ->first(function (AllowedOrigin $allowedOrigin) use ($origin): bool {
                if (! $allowedOrigin->is_active) {
                    return false;
                }

                return $this->originMatcher->matches((string) $allowedOrigin->origin, $origin);
            });

        return $matched;
    }
}
