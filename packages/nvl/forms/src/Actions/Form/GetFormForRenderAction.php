<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\Form;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Nvl\Forms\Models\Form;
use Nvl\Tenancy\Services\TenantBoundary;

/**
 * Resolves a public form by model, UUID, or handle with render relations loaded.
 */
final class GetFormForRenderAction
{
    /** Create the canonical tenant-aware public form resolver. */
    public function __construct(private readonly TenantBoundary $boundary) {}

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
        $identifier = $formIdentifier instanceof Form
            ? (string) $formIdentifier->getKey()
            : $formIdentifier;

        $query = $this->boundary->query(Form::query(), 'forms.forms')
            ->withResolvedTranslations()
            ->where(function ($identity) use ($identifier): void {
                $identity->where('handle', $identifier);
                if (Str::isUuid($identifier)) {
                    $identity->orWhere('id', $identifier);
                }
            });

        $form = $query->firstOrFail();

        $form->loadMissing(['allowedOrigins', 'translations']);
        $this->boundary->assertRecord($form, 'forms.forms');

        return $form;
    }
}
