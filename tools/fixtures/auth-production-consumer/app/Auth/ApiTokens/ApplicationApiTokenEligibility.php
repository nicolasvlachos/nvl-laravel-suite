<?php

declare(strict_types=1);

namespace App\Auth\ApiTokens;

use Nvl\Auth\Contracts\ApiTokenEligibility;
use Nvl\Auth\Enums\ApiTokenOperation;
use Nvl\Auth\Results\EligibilityDecision;
use Nvl\Auth\ValueObjects\PrincipalReference;

/**
 * Applies the consumer's explicit personal API-token eligibility policy.
 */
final readonly class ApplicationApiTokenEligibility implements ApiTokenEligibility
{
    /**
     * Permit package-active projected users in this single-tenant consumer.
     */
    public function evaluate(
        PrincipalReference $principal,
        ApiTokenOperation $operation,
    ): EligibilityDecision {
        return new EligibilityDecision(allowed: true);
    }
}
