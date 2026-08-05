<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\Form;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nvl\Forms\Models\Form;

/**
 * Orchestrates form retrieval, optional view recording, and display loading.
 *
 * Delegation to RecordFormViewAction is deliberate domain orchestration that
 * keeps the opt-in view side effect on the canonical display path.
 */
final class ShowFormAction
{
    /**
     * Create the action with view-recording dependency.
     *
     * @param  RecordFormViewAction  $recordFormView  Action for recording form views
     */
    public function __construct(
        private readonly RecordFormViewAction $recordFormView,
    ) {}

    /**
     * Resolve the form for display and optionally record a view event.
     *
     * @param  Form|string  $form  Form instance or identifier
     * @param  bool  $recordView  Whether the current request should be counted as a view
     * @param  string|null  $origin  Origin header or referrer value
     * @param  string|null  $ipAddress  Visitor IP address
     * @param  string|null  $userAgent  Visitor user agent
     * @param  string|null  $sessionId  Session identifier when available
     * @param  Authenticatable|null  $actor  Actor initiating the view
     * @return Form Form with the required relationships eagerly loaded
     */
    public function execute(
        Form|string $form,
        bool $recordView = true,
        ?string $origin = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $sessionId = null,
        ?Authenticatable $actor = null,
    ): Form {
        $formModel = $form instanceof Form ? $form : Form::findOrFail($form);

        if ($recordView) {
            $formModel = $this->recordFormView->execute(
                $formModel,
                $origin,
                $ipAddress,
                $userAgent,
                $sessionId
            );
        }

        return $formModel->load([
            'entries' => static function (HasMany $query): void {
                $query->latest()
                    ->select('id', 'form_id', 'subject', 'email', 'first_name', 'last_name', 'is_spam', 'created_at')
                    ->limit(10);
            },
            'allowedOrigins',
            'translations',
        ])->loadMissing([
            'analytics' => static function (HasMany $query): void {
                $query->whereDate('created_at', '>=', now()->subDays(30)->toDateString())
                    ->orderBy('created_at', 'desc')
                    ->limit(100);
            },
        ]);
    }
}
