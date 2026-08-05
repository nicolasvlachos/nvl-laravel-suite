<?php

declare(strict_types=1);

namespace Nvl\Forms\Enums;

/**
 * Enum for form analytics event types.
 */
enum FormAnalyticEventType: string
{
    case VIEW = 'view';
    case SUBMISSION = 'submission';
    case SPAM_BLOCKED = 'spam_blocked';
    case RATE_LIMITED = 'rate_limited';
    case ERROR = 'error';
    case VALIDATION_FAILED = 'validation_failed';

    /**
     * Get human readable label for the event type.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::VIEW => (string) trans('forms::forms/forms.options.event_types.view'),
            self::SUBMISSION => (string) trans('forms::forms/forms.options.event_types.submission'),
            self::SPAM_BLOCKED => (string) trans('forms::forms/forms.options.event_types.spam_blocked'),
            self::RATE_LIMITED => (string) trans('forms::forms/forms.options.event_types.rate_limited'),
            self::ERROR => (string) trans('forms::forms/forms.options.event_types.error'),
            self::VALIDATION_FAILED => (string) trans('forms::forms/forms.options.event_types.validation_failed'),
        };
    }

    /**
     * Get description for the event type.
     */
    public function description(): string
    {
        return match ($this) {
            self::VIEW => (string) trans('forms::forms/forms.descriptions.event_types.view'),
            self::SUBMISSION => (string) trans('forms::forms/forms.descriptions.event_types.submission'),
            self::SPAM_BLOCKED => (string) trans('forms::forms/forms.descriptions.event_types.spam_blocked'),
            self::RATE_LIMITED => (string) trans('forms::forms/forms.descriptions.event_types.rate_limited'),
            self::ERROR => (string) trans('forms::forms/forms.descriptions.event_types.error'),
            self::VALIDATION_FAILED => (string) trans('forms::forms/forms.descriptions.event_types.validation_failed'),
        };
    }

    /**
     * Check if this is a positive event (successful interaction).
     */
    public function isPositive(): bool
    {
        return match ($this) {
            self::VIEW, self::SUBMISSION => true,
            default => false,
        };
    }

    /**
     * Check if this is a security-related event.
     */
    public function isSecurity(): bool
    {
        return match ($this) {
            self::SPAM_BLOCKED, self::RATE_LIMITED => true,
            default => false,
        };
    }

    /**
     * Return enum options for select inputs.
     *
     * @return list<array{value:string,label:string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $eventType): array => ['value' => $eventType->value, 'label' => $eventType->getLabel()],
            self::cases(),
        );
    }
}
