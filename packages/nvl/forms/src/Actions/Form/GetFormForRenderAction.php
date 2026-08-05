<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\Form;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Nvl\Forms\Models\Form;

/**
 * Resolves a public form by model, UUID, or handle with render relations loaded.
 */
final class GetFormForRenderAction
{
    /**
     * Execute the form retrieval for rendering.
     *
     * Accepts a pre-loaded Form instance to avoid redundant queries
     * when the model was already resolved by middleware.
     *
     * @param  Form|string  $formIdentifier  Form model, ID, or handle
     * @return Form Form model with loaded relationships
     *
     * @throws ModelNotFoundException If the form cannot be resolved
     */
    public function execute(Form|string $formIdentifier): Form
    {
        if ($formIdentifier instanceof Form) {
            $formIdentifier->loadMissing(['allowedOrigins', 'translations']);

            return $formIdentifier;
        }

        $query = Form::query()
            ->withResolvedTranslations()
            ->where('handle', $formIdentifier);

        if (Str::isUuid($formIdentifier)) {
            $query->orWhere('id', $formIdentifier);
        }

        $form = $query->firstOrFail();

        $form->loadMissing(['allowedOrigins', 'translations']);

        return $form;
    }
}
