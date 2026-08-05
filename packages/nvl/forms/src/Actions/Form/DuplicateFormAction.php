<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\Form;

use Exception;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Nvl\Forms\Enums\FormStatus;
use Nvl\Forms\Events\FormChangedEvent;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormTranslation;
use Nvl\Forms\Services\FormHandleService;
use Nvl\Translatable\Services\TranslationWriter;
use Throwable;

/**
 * Orchestrates form duplication with relationship copying and domain activity capture.
 *
 * Uses Eloquent replicate() for efficient attribute copying, delegates handle
 * generation to FormHandleService, resets all statistics, and duplicates
 * allowed origins with fresh usage counters.
 *
 * @see FormHandleService
 */
final class DuplicateFormAction
{
    /**
     * @param  FormHandleService  $handleService  Generates unique handles for the duplicate
     */
    public function __construct(
        private readonly FormHandleService $handleService,
        private readonly TranslationWriter $translationWriter,
    ) {}

    /**
     * Execute the form duplication within a database transaction.
     *
     * Replicates the original form, generates a unique handle, resets statistics
     * duplicates allowed origins, and records domain activities.
     *
     * @param  Form|string  $form  Form instance or identifier to duplicate
     * @param  string|null  $newName  Optional new name for the duplicated form
     * @param  Authenticatable|null  $actor  Actor performing the duplication
     * @return Form The duplicated form instance with allowedOrigins loaded
     *
     * @throws Exception When duplication fails
     * @throws Throwable When transaction fails
     */
    public function execute(Form|string $form, ?string $newName = null, ?Authenticatable $actor = null): Form
    {
        $originalForm = $form instanceof Form
            ? $form->load(['allowedOrigins', 'translations'])
            : Form::with(['allowedOrigins', 'translations'])->findOrFail($form);

        /** @var Form $newForm */
        $newForm = DB::transaction(function () use ($originalForm, $newName) {
            $newForm = $originalForm->replicate();

            $sourceName = $originalForm->displayName();
            $duplicateName = $newName ?? ($sourceName.' (Copy)');
            $newForm->handle = $this->handleService->generateUniqueHandle($duplicateName);
            $newForm->status = FormStatus::DRAFT;
            $newForm->revision = 1;

            // Reset all statistics and usage data
            $newForm->submissions_count = 0;
            $newForm->views_count = 0;
            $newForm->spam_count = 0;
            $newForm->last_used_at = null;
            $newForm->first_used_at = null;

            $newForm->saveQuietly();

            $this->duplicateAllowedOrigins($originalForm, $newForm);
            $this->duplicateTranslations($originalForm, $newForm, $newName);

            $newForm->load(['allowedOrigins', 'translations']);

            return $newForm;
        });

        event(FormChangedEvent::for(
            form: $newForm,
            operation: 'duplicated',
            actor: $actor,
            context: ['source_form_id' => $originalForm->id],
        ));

        return $newForm;
    }

    /**
     * Duplicate allowed origins with fresh usage statistics.
     *
     * Replicates each origin record, assigns it to the new form,
     * and resets usage_count and last_used_at.
     *
     * @param  Form  $originalForm  Source form with loaded allowedOrigins
     * @param  Form  $newForm  Target form to attach duplicated origins to
     */
    private function duplicateAllowedOrigins(Form $originalForm, Form $newForm): void
    {
        foreach ($originalForm->allowedOrigins as $originalOrigin) {
            $newOrigin = $originalOrigin->replicate();
            $newOrigin->form_id = $newForm->id;
            $newOrigin->usage_count = 0;
            $newOrigin->last_used_at = null;
            $newOrigin->saveQuietly();
        }
    }

    /**
     * Duplicate every locale row while applying the explicit replacement name when supplied.
     */
    private function duplicateTranslations(
        Form $originalForm,
        Form $newForm,
        ?string $newName,
    ): void {
        $fields = $originalForm->translationDefinition()->fields;
        /** @var array<string, array<string, mixed>> $translations */
        $translations = $originalForm->translations
            ->mapWithKeys(function (FormTranslation $translation) use ($fields, $newName): array {
                $attributes = $translation->only($fields);

                if ($newName !== null) {
                    $attributes['name'] = $newName;
                }

                return [$translation->locale => $attributes];
            })
            ->all();

        $this->translationWriter->replace($newForm, $translations);
    }
}
