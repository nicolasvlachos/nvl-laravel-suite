<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\Form;

use Exception;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Nvl\Forms\Contracts\CreateFormContract;
use Nvl\Forms\Data\Mutations\MutateFormPayload;
use Nvl\Forms\Events\FormChangedEvent;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Services\FormAllowedOriginService;
use Nvl\Forms\Services\FormHandleService;
use Nvl\Forms\Services\FormTranslationPayloadMapper;
use Nvl\Translatable\Services\TranslationWriter;
use Spatie\LaravelData\Optional;
use Throwable;

/**
 * Orchestrates form creation with handle generation and origin persistence.
 *
 * Delegates handle generation to FormHandleService and allowed origin creation
 * to FormAllowedOriginService, keeping this action as a pure orchestrator.
 *
 * @see FormHandleService
 * @see FormAllowedOriginService
 */
final class CreateFormAction implements CreateFormContract
{
    /**
     * @param  FormHandleService  $handleService  Generates and validates form handles
     * @param  FormAllowedOriginService  $originService  Manages allowed origin creation
     */
    public function __construct(
        private readonly FormHandleService $handleService,
        private readonly FormAllowedOriginService $originService,
        private readonly FormTranslationPayloadMapper $translationPayloadMapper,
        private readonly TranslationWriter $translationWriter,
    ) {}

    /**
     * Execute the form creation within a database transaction.
     *
     * Generates a unique handle if not provided, persists the form,
     * creates any allowed origins, and returns the fresh model.
     *
     * @param  MutateFormPayload  $data  Validated form mutation data
     * @param  Authenticatable|null  $actor  Authenticated actor performing the operation
     * @return Form The created form instance with allowedOrigins loaded
     *
     * @throws Exception When handle already exists or model refresh fails
     * @throws Throwable When transaction fails
     */
    public function execute(MutateFormPayload $data, ?Authenticatable $actor = null): Form
    {
        $form = DB::transaction(function () use ($data) {
            $translations = $data->translations instanceof Optional
                ? []
                : $data->translations;
            $formData = $data->except(
                'allowedOrigins',
                'translations',
                'translationMode',
                'expectedRevision',
            )->toModelFiltered();
            $translationRows = $this->translationPayloadMapper->rows($translations);

            // Generate handle if not provided
            $handle = $formData['handle'] ?? null;

            if (! is_string($handle) || $handle === '') {
                $handle = $this->handleService->generateHandle(
                    $this->firstTranslationName($translationRows),
                );
                $formData['handle'] = $handle;
            }

            $this->handleService->validateUniqueness($handle);

            $form = new Form;
            $form->fill($formData);
            $form->save();

            if (is_array($data->allowedOrigins)) {
                $this->originService->createOrigins($form, $data->allowedOrigins);
            }

            $this->translationWriter->sync(
                $form,
                $translationRows,
                $data->translationMode,
            );

            $freshForm = $form->fresh();
            if ($freshForm === null) {
                throw new Exception(
                    (string) trans('forms::forms/shared.messages.error.refresh_failed', [
                        'item' => (string) trans('forms::forms/general.entities.singular'),
                    ])
                );
            }

            return $freshForm->loadMissing(['allowedOrigins', 'translations']);
        });

        event(FormChangedEvent::for($form, 'created', $actor));

        return $form;
    }

    /**
     * @param  array<string, array<string, mixed>>  $translations
     */
    private function firstTranslationName(array $translations): ?string
    {
        foreach ($translations as $translation) {
            $name = $translation['name'] ?? null;

            if (is_string($name) && trim($name) !== '') {
                return $name;
            }
        }

        return null;
    }
}
