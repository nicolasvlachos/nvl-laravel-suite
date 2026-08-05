<?php

declare(strict_types=1);

namespace Nvl\Forms\Enums;

/**
 * Form rendering mode options.
 */
enum FormType: string
{
    case LANDING_PAGE = 'landing_page';
    case IFRAME = 'iframe';

    /**
     * Resolve translated label for this form type.
     */
    public function getLabel(): string
    {
        return (string) trans('forms::forms/forms.options.type.'.$this->value);
    }

    /**
     * Return enum options for select inputs.
     *
     * @return list<array{value:string,label:string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $type): array => ['value' => $type->value, 'label' => $type->getLabel()],
            self::cases(),
        );
    }
}
