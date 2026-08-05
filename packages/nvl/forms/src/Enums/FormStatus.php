<?php

declare(strict_types=1);

namespace Nvl\Forms\Enums;

/**
 * Enum for form status values.
 */
enum FormStatus: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case PAUSED = 'paused';
    case ARCHIVED = 'archived';

    /**
     * Get human readable label for the status.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::DRAFT => (string) trans('forms::forms/forms.options.status.draft'),
            self::ACTIVE => (string) trans('forms::forms/forms.options.status.active'),
            self::PAUSED => (string) trans('forms::forms/forms.options.status.paused'),
            self::ARCHIVED => (string) trans('forms::forms/forms.options.status.archived'),
        };
    }

    /**
     * Get description for the status.
     */
    public function description(): string
    {
        return match ($this) {
            self::DRAFT => (string) trans('forms::forms/forms.descriptions.status.draft'),
            self::ACTIVE => (string) trans('forms::forms/forms.descriptions.status.active'),
            self::PAUSED => (string) trans('forms::forms/forms.descriptions.status.paused'),
            self::ARCHIVED => (string) trans('forms::forms/forms.descriptions.status.archived'),
        };
    }

    /**
     * Get semantic color token for status display.
     */
    public function colorClass(): string
    {
        return match ($this) {
            self::DRAFT => 'neutral',
            self::ACTIVE => 'success',
            self::PAUSED => 'warning',
            self::ARCHIVED => 'danger',
        };
    }

    /**
     * Check if form can accept submissions in this status.
     */
    public function canAcceptSubmissions(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * Check if form can be edited in this status.
     */
    public function canBeEdited(): bool
    {
        return match ($this) {
            self::DRAFT, self::PAUSED => true,
            self::ACTIVE, self::ARCHIVED => false,
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
            static fn (self $status): array => ['value' => $status->value, 'label' => $status->getLabel()],
            self::cases(),
        );
    }
}
