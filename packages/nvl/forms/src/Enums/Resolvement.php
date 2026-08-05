<?php

declare(strict_types=1);

namespace Nvl\Forms\Enums;

/**
 * Form submission resolution strategy options.
 */
enum Resolvement: string
{
    case CUSTOM = 'custom';
    case ENTRIES = 'entries';

    /**
     * Resolve translated label for this resolvement strategy.
     */
    public function getLabel(): string
    {
        return (string) trans('forms::forms/forms.options.resolvement.'.$this->value);
    }

    /**
     * Return enum options for select inputs.
     *
     * @return list<array{value:string,label:string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $resolvement): array => ['value' => $resolvement->value, 'label' => $resolvement->getLabel()],
            self::cases(),
        );
    }
}
