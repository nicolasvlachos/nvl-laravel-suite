<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\Form;

use Illuminate\Support\Facades\DB;
use Nvl\Forms\Actions\AllowedOrigin\RecordAllowedOriginUsageAction;
use Nvl\Forms\Enums\FormAnalyticEventType;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Services\FormOriginAccessService;
use Throwable;

/**
 * Orchestrates atomic view counters, analytics, and allowed-origin usage.
 *
 * The approved action composition keeps the three related bookkeeping writes
 * within one transaction.
 */
final class RecordFormViewAction
{
    /**
     * @param  RecordFormAnalyticAction  $recordFormAnalytic  Analytics recorder
     * @param  FormOriginAccessService  $originAccess  Origin access resolver
     * @param  RecordAllowedOriginUsageAction  $recordAllowedOriginUsage  Allowed origin usage recorder
     */
    public function __construct(
        private readonly RecordFormAnalyticAction $recordFormAnalytic,
        private readonly FormOriginAccessService $originAccess,
        private readonly RecordAllowedOriginUsageAction $recordAllowedOriginUsage,
    ) {}

    /**
     * Record the view for the given form.
     *
     * @param  Form|string  $form  Form instance or identifier
     * @param  string|null  $origin  Origin header or referrer value
     * @param  string|null  $ipAddress  Visitor IP address
     * @param  string|null  $userAgent  Visitor user agent
     * @param  string|null  $sessionId  Session identifier when available
     * @return Form Updated form instance
     *
     * @throws Throwable
     */
    public function execute(
        Form|string $form,
        ?string $origin,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $sessionId
    ): Form {
        return DB::transaction(function () use ($form, $origin, $ipAddress, $userAgent, $sessionId) {
            $formModel = $form instanceof Form
                ? $form
                : Form::findOrFail($form);

            $formModel->increment('views_count');

            $this->recordFormAnalytic->execute(
                form: $formModel,
                eventType: FormAnalyticEventType::VIEW,
                origin: $origin,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                sessionId: $sessionId,
            );

            if ($origin !== null && $origin !== '' && $formModel->restrict_public_access) {
                $matchedOrigin = $this->originAccess->resolveMatchingOrigin($formModel, $origin);
                if ($matchedOrigin !== null) {
                    $this->recordAllowedOriginUsage->execute($matchedOrigin);
                }
            }

            $fresh = $formModel->fresh();

            if (! $fresh instanceof Form) {
                throw new \RuntimeException('The form could not be refreshed after recording its view.');
            }

            return $fresh;
        });
    }
}
