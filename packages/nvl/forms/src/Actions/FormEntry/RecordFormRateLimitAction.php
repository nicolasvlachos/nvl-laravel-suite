<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\FormEntry;

use Nvl\Forms\Contracts\FormRateLimiter;
use Nvl\Forms\Models\Form;

/**
 * Records a submission attempt for rate limit tracking.
 */
final class RecordFormRateLimitAction
{
    /**
     * Create the action with the rate-limit service.
     *
     * @param  FormRateLimiter  $rateLimitService  Rate limit service
     */
    public function __construct(private readonly FormRateLimiter $rateLimitService) {}

    /**
     * Record a submission attempt for the given form and IP.
     *
     * @param  Form|string  $form  The form or its ID
     * @param  string  $ipAddress  Submitter IP address
     * @param  string|null  $origin  Request Origin header value
     * @param  string|null  $userAgent  Request user agent
     * @param  string|null  $sessionId  Session identifier
     */
    public function execute(Form|string $form, string $ipAddress, ?string $origin = null, ?string $userAgent = null, ?string $sessionId = null): void
    {
        $formModel = $form instanceof Form ? $form : Form::findOrFail($form);
        $this->rateLimitService->recordSubmissionAttempt($formModel, $ipAddress, $origin, $userAgent, $sessionId);
    }
}
