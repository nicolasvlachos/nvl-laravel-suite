<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\FormEntry;

use Illuminate\Support\Facades\Route;
use Nvl\Forms\Models\Form;

/**
 * Generates form navigation tabs for the entries interface.
 * This action handles the business logic for creating
 * contextual navigation between form sections.
 */
final class GetFormNavigationAction
{
    /**
     * Execute the navigation generation.
     *
     * @param  Form  $form  Form model for navigation
     * @param  bool  $overviewActive  Whether the overview tab should be active
     * @return array<int, array{label:string, href:string, active:bool}>
     */
    public function execute(Form $form, bool $overviewActive): array
    {
        return [
            [
                'label' => (string) trans('forms::forms/general.tabs.overview'),
                'href' => Route::has('forms.show') ? route('forms.show', $form) : "/forms/{$form->id}",
                'active' => $overviewActive,
            ],
        ];
    }
}
