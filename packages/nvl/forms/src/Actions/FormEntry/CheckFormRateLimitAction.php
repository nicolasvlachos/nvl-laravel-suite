<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\FormEntry;

use Nvl\Forms\Contracts\FormRateLimiter;
use Nvl\Forms\Models\Form;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Checks if a submission is rate limited and throws when exceeded.
 */
final class CheckFormRateLimitAction
{
    /**
     * Create the action with the rate-limit service.
     *
     * @param  FormRateLimiter  $rateLimitService  Rate limit service
     */
    public function __construct(private readonly FormRateLimiter $rateLimitService) {}

    /**
     * Enforce rate limiting for a form submission.
     *
     * @param  Form|string  $form  The form or its ID
     * @param  string  $ipAddress  Submitter IP address
     * @param  string|null  $origin  Request Origin header value for analytics
     * @param  string|null  $userAgent  Request user agent for analytics
     * @param  string|null  $sessionId  Session identifier for analytics
     *
     * @throws TooManyRequestsHttpException When rate limit is exceeded
     */
    public function execute(Form|string $form, string $ipAddress, ?string $origin = null, ?string $userAgent = null, ?string $sessionId = null): void
    {
        $formModel = $form instanceof Form ? $form : Form::findOrFail($form);

        $attempt = $this->rateLimitService->consumeSubmissionAttempt($formModel, $ipAddress, $origin, $userAgent, $sessionId);

        if (! $attempt->allowed) {
            throw new TooManyRequestsHttpException(
                max(1, $attempt->retryAfterSeconds),
                (string) trans('forms::forms/shared.messages.error.rate_limit_exceeded'),
            );
        }
    }
}
