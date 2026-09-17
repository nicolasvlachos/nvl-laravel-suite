<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\Form;

use Illuminate\Support\Facades\DB;
use Nvl\Forms\Enums\FormAnalyticEventType;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormAnalytic;
use Nvl\Tenancy\Services\TenantBoundary;

/**
 * Records a forms analytics event.
 */
final class RecordFormAnalyticAction
{
    /** Create the ownership-aware recorder. */
    public function __construct(private readonly TenantBoundary $boundary) {}

    /**
     * Create a forms analytics event.
     *
     * @param  FormAnalyticEventType|string  $eventType  Analytic event type
     * @param  array<string, mixed>|null  $metadata  Optional event metadata
     */
    public function execute(
        Form|string $form,
        FormAnalyticEventType|string $eventType,
        ?string $origin = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $sessionId = null,
        ?array $metadata = null,
    ): FormAnalytic {
        $formId = $form instanceof Form ? (string) $form->getKey() : (string) $form;
        $canonical = $this->boundary->query(Form::query(), 'forms.forms')->findOrFail($formId);
        $event = $eventType instanceof FormAnalyticEventType
            ? $eventType
            : FormAnalyticEventType::from((string) $eventType);

        return DB::transaction(fn (): FormAnalytic => FormAnalytic::create([
            'form_id' => $canonical->getKey(),
            ...$this->childOwnership($canonical),
            'event_type' => $event,
            'origin' => $origin,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'session_id' => $sessionId,
            'metadata' => $metadata,
        ]));
    }

    /** @return array{tenant_id?:mixed} */
    private function childOwnership(Form $form): array
    {
        return array_key_exists('tenant_id', $form->getAttributes())
            ? ['tenant_id' => $form->getRawOriginal('tenant_id')]
            : [];
    }
}
