<?php

declare(strict_types=1);

namespace Nvl\Forms\Services;

use Nvl\Forms\Data\Display\FormShowBadge;
use Nvl\Forms\Data\Display\FormShowLinkState;
use Nvl\Forms\Data\Display\FormShowSecurityState;
use Nvl\Forms\Data\Display\FormShowStat;
use Nvl\Forms\Data\Display\FormShowStates;
use Nvl\Forms\Data\Display\FormShowStatusState;
use Nvl\Forms\Enums\FormStatus;
use Nvl\Forms\Models\Form;
use Spatie\LaravelData\DataCollection;

/**
 * Pure derivation service that computes display state for the forms show page.
 */
final class FormShowStateResolver
{
    /**
     * Resolve all derived display state concerns for the provided form.
     */
    public function resolve(Form $form): FormShowStates
    {
        $yesLabel = (string) trans('forms::forms/shared.tables.boolean.yes');
        $noLabel = (string) trans('forms::forms/shared.tables.boolean.no');

        $status = $this->resolveStatusState($form);
        $security = $this->resolveSecurityState($form, $yesLabel, $noLabel);
        $links = $this->resolveLinkState($form);
        $stats = $this->resolveStats($form);

        return new FormShowStates(
            status: $status,
            security: $security,
            links: $links,
            stats: $stats,
        );
    }

    /**
     * Resolve status label + badge variant for the form.
     */
    private function resolveStatusState(Form $form): FormShowStatusState
    {
        $status = $form->status;

        return new FormShowStatusState(
            current: $status->value,
            label: $status->getLabel(),
            variant: $this->resolveStatusVariant($status),
        );
    }

    /**
     * Resolve security, availability, and type/resolvement display state.
     */
    private function resolveSecurityState(
        Form $form,
        string $yesLabel,
        string $noLabel,
    ): FormShowSecurityState {
        $rateLimitDisplay = number_format((int) $form->rate_limit_per_hour);
        $resolvement = $form->resolvement;
        $type = $form->type;

        return new FormShowSecurityState(
            yesLabel: $yesLabel,
            noLabel: $noLabel,
            isRestricted: (bool) $form->restrict_public_access,
            restrictPublicAccess: $this->booleanBadge((bool) $form->restrict_public_access, $yesLabel, $noLabel),
            allowMultipleRegistrations: $this->booleanBadge((bool) $form->allow_multiple_registrations, $yesLabel, $noLabel),
            enableHoneypot: $this->booleanBadge((bool) $form->enable_honeypot, $yesLabel, $noLabel),
            requireCsrf: $this->booleanBadge((bool) $form->require_csrf, $yesLabel, $noLabel),
            enableRateLimiting: $this->booleanBadge((bool) $form->enable_rate_limiting, $yesLabel, $noLabel),
            dateRestricted: $this->booleanBadge((bool) $form->date_restricted, $yesLabel, $noLabel),
            availability: $this->availabilityBadge($form, $yesLabel, $noLabel),
            rateLimitPerHourDisplay: $rateLimitDisplay,
            resolvementLabel: $resolvement->getLabel(),
            typeLabel: $type->getLabel(),
        );
    }

    /**
     * Resolve public and embed links for the form.
     */
    private function resolveLinkState(Form $form): FormShowLinkState
    {
        $viewUrl = route('forms.public.show', ['form' => $form->id]);
        $iframeId = 'gct-form-'.substr($form->id, 0, 8);
        $embedCode = '<iframe id="'.$iframeId.'" src="'.$viewUrl.'" width="100%" style="border:0;min-height:400px;" loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>'
            .'<script>(function(){var f=document.getElementById("'.$iframeId.'");if(!f)return;window.addEventListener("message",function(e){if(f.contentWindow&&e.source===f.contentWindow&&e.data&&e.data.type==="resize"&&e.data.height>0){f.style.height=e.data.height+"px";}});})();</script>';

        return new FormShowLinkState(
            viewUrl: $viewUrl,
            embedCode: $embedCode,
        );
    }

    /**
     * Resolve show-page stat cards.
     *
     * @return DataCollection<int, FormShowStat>
     */
    private function resolveStats(Form $form): DataCollection
    {
        $statusLabel = $form->status->getLabel();

        $isRestricted = (bool) $form->restrict_public_access;
        $accessLabel = $isRestricted
            ? (string) trans('forms::forms/forms.options.access.private')
            : (string) trans('forms::forms/forms.options.access.public');
        $lastUsedAt = $form->last_used_at?->toDateTimeString() ?? (string) trans('forms::forms/shared.tables.ui.empty');

        /** @var array<int, FormShowStat> $items */
        $items = [
            new FormShowStat(
                icon: 'Signal',
                label: (string) trans('forms::forms/forms.fields.status.label'),
                value: $statusLabel,
                description: (string) trans('forms::forms/forms.fields.status.help'),
            ),
            new FormShowStat(
                icon: 'FileText',
                label: (string) trans('forms::forms/forms.additional_fields.submissions_count.label'),
                value: number_format((int) $form->submissions_count),
                description: (string) trans('forms::forms/forms.additional_fields.submissions_count.help'),
            ),
            new FormShowStat(
                icon: 'Eye',
                label: (string) trans('forms::forms/forms.additional_fields.views_count.label'),
                value: number_format((int) $form->views_count),
                description: (string) trans('forms::forms/forms.additional_fields.views_count.help'),
            ),
            new FormShowStat(
                icon: 'ShieldAlert',
                label: (string) trans('forms::forms/forms.additional_fields.spam_count.label'),
                value: number_format((int) $form->spam_count),
                description: (string) trans('forms::forms/forms.additional_fields.spam_count.help'),
            ),
            new FormShowStat(
                icon: $isRestricted ? 'Lock' : 'Globe',
                label: (string) trans('forms::forms/forms.fields.restrict_public_access.label'),
                value: $accessLabel,
                description: (string) trans('forms::forms/forms.fields.restrict_public_access.help'),
            ),
            new FormShowStat(
                icon: 'Calendar',
                label: (string) trans('forms::forms/forms.additional_fields.last_used_at.label'),
                value: $lastUsedAt,
                description: (string) trans('forms::forms/forms.additional_fields.last_used_at.help'),
            ),
        ];

        return new DataCollection(FormShowStat::class, $items);
    }

    /**
     * Resolve status badge variant from a status value.
     */
    private function resolveStatusVariant(FormStatus $status): string
    {
        return match ($status) {
            FormStatus::ACTIVE => 'secondary',
            FormStatus::ARCHIVED => 'destructive',
            default => 'main',
        };
    }

    /**
     * Build a standard yes/no badge payload.
     */
    private function booleanBadge(bool $value, string $yesLabel, string $noLabel): FormShowBadge
    {
        return new FormShowBadge(
            label: $value ? $yesLabel : $noLabel,
            variant: $value ? 'secondary' : 'main',
        );
    }

    /**
     * Build the current availability badge from date restrictions.
     */
    private function availabilityBadge(Form $form, string $yesLabel, string $noLabel): FormShowBadge
    {
        $isAvailable = $form->isAvailableNow();

        return new FormShowBadge(
            label: $isAvailable ? $yesLabel : $noLabel,
            variant: $isAvailable ? 'secondary' : 'main',
        );
    }
}
