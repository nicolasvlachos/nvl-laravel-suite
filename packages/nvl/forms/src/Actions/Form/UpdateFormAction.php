<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\Form;

use Exception;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Nvl\Forms\Data\Mutations\MutateFormPayload;
use Nvl\Forms\Events\FormChangedEvent;
use Nvl\Forms\Exceptions\FormException;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Services\FormAllowedOriginService;
use Nvl\Forms\Services\FormHandleService;
use Nvl\Forms\Services\FormTranslationPayloadMapper;
use Nvl\Translatable\Services\TranslationWriter;
use Spatie\LaravelData\Optional;
use Throwable;

/**
 * Orchestrates form updates with handle validation and origin sync.
 *
 * Delegates handle uniqueness validation to FormHandleService and allowed origin
 * synchronization to FormAllowedOriginService, keeping this action as a pure orchestrator.
 *
 * @see FormHandleService
 * @see FormAllowedOriginService
 */
final class UpdateFormAction
{
    /**
     * @param  FormHandleService  $handleService  Validates handle uniqueness
     * @param  FormAllowedOriginService  $originService  Manages allowed origin sync
     */
    public function __construct(
        private readonly FormHandleService $handleService,
        private readonly FormAllowedOriginService $originService,
        private readonly FormTranslationPayloadMapper $translationPayloadMapper,
        private readonly TranslationWriter $translationWriter,
    ) {}

    /**
     * Execute the form update within a database transaction.
     *
     * Validates handle uniqueness if changed, updates form attributes,
     * syncs allowed origins, and returns the refreshed model.
     *
     * @param  Form|string  $form  Form instance or identifier
     * @param  MutateFormPayload  $data  Updated form mutation data
     * @param  Authenticatable|null  $actor  Authenticated actor performing the update
     * @return Form Updated form instance with allowedOrigins loaded
     *
     * @throws Exception When handle is not unique
     * @throws Throwable When transaction fails
     */
    public function execute(Form|string $form, MutateFormPayload $data, ?Authenticatable $actor = null): Form
    {
        $formId = $form instanceof Form ? $form->id : $form;

        $updated = DB::transaction(function () use ($formId, $data) {
            $form = Form::query()->lockForUpdate()->findOrFail($formId);
            $expectedRevision = $data->expectedRevision instanceof Optional
                ? null
                : $data->expectedRevision;

            if ($expectedRevision === null || $form->revision !== $expectedRevision) {
                throw new FormException('The form was changed by another writer.', 409);
            }

            // Validate handle uniqueness if changed
            if (isset($data->handle) && ! ($data->handle instanceof Optional) && $form->handle !== $data->handle) {
                $this->handleService->validateUniqueness($data->handle, $form->id);
            }

            // Update form data
            $payload = $data->except(
                'translationMode',
                'translations',
                'expectedRevision',
            )->toModelPatch();
            $allowedOrigins = $payload['allowed_origins'] ?? null;
            unset($payload['allowed_origins']);

            $translations = $data->translations instanceof Optional
                ? null
                : $data->translations;

            $payload['revision'] = $form->revision + 1;

            $form->fill($payload);
            $form->save();

            if (is_array($allowedOrigins)) {
                $this->originService->syncOrigins($form, array_values($allowedOrigins));
            }

            $translationRows = $this->translationPayloadMapper->rows($translations);

            if ($translationRows !== [] || $data->translationMode->value === 'replace') {
                $this->translationWriter->sync(
                    $form,
                    $translationRows,
                    $data->translationMode,
                );
            }

            $form->refresh();
            $form->loadMissing(['allowedOrigins', 'translations']);

            return $form;
        });

        event(FormChangedEvent::for($updated, 'updated', $actor));

        return $updated;
    }
}
