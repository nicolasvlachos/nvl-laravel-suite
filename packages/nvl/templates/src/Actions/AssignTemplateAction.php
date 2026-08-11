<?php

declare(strict_types=1);

namespace Nvl\Templates\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Templates\Contracts\TemplateAuthorization;
use Nvl\Templates\Data\Mutations\AssignTemplateData;
use Nvl\Templates\Data\TemplateActorData;
use Nvl\Templates\Enums\TemplateAbility;
use Nvl\Templates\Enums\TemplateStatus;
use Nvl\Templates\Events\TemplateChanged;
use Nvl\Templates\Exceptions\StaleTemplateException;
use Nvl\Templates\Models\Template;
use Nvl\Templates\Models\TemplateAssignment;
use Nvl\Templates\Services\CanonicalJson;
use Nvl\Templates\Services\TemplateContentGuard;
use Nvl\Templates\Services\TemplateDefinitionRegistry;
use Nvl\Templates\Services\TemplateOwnerRegistry;
use Nvl\Templates\Services\TemplateVersionResolver;
use Nvl\Templates\Support\TemplatesConfiguration;

/**
 * Creates or updates a unique owner/profile template assignment.
 */
final readonly class AssignTemplateAction
{
    public function __construct(
        private TemplateAuthorization $authorization,
        private TemplateOwnerRegistry $owners,
        private TemplateDefinitionRegistry $definitions,
        private TemplateContentGuard $guard,
        private TemplateVersionResolver $versions,
    ) {}

    /**
     * Create or revise one exact owner and profile assignment.
     */
    public function execute(
        Template|string $template,
        AssignTemplateData $data,
        TemplateActorData $actor,
    ): TemplateAssignment {
        $templateId = $template instanceof Template ? $template->id : $template;
        $this->authorization->authorize(
            TemplateAbility::Assign,
            $actor,
            [
                'template_id' => $templateId,
                'owner_type' => $data->ownerType,
                'owner_id' => $data->ownerId,
                'profile' => $data->profile,
                'version_id' => $data->versionId,
            ],
        );
        $this->owners->resolve($data->ownerType, $data->ownerId);
        $this->guard->settings($data->settings);

        try {
            return DB::connection(TemplatesConfiguration::connection())
                ->transaction(function () use ($actor, $data, $templateId): TemplateAssignment {
                    $template = Template::query()->lockForUpdate()->findOrFail($templateId);
                    $definition = $this->definitions->get($template->key);
                    $canonicalJson = new CanonicalJson;

                    if ($template->status !== TemplateStatus::Active
                        || $template->renderer !== $definition->renderer
                        || $canonicalJson->digest($template->schema)
                            !== $canonicalJson->digest($definition->schema)) {
                        throw new InvalidArgumentException(
                            "Template [{$template->key}] must be active and synchronized before assignment.",
                        );
                    }

                    if (! in_array($data->profile, $definition->profiles, true)) {
                        throw new InvalidArgumentException(
                            "Profile [{$data->profile}] is not declared for template [{$template->key}].",
                        );
                    }

                    if ($data->versionId !== null) {
                        $this->versions->forAssignment($template, $data->versionId);
                    }

                    $assignment = TemplateAssignment::query()
                        ->where('owner_type', $data->ownerType)
                        ->where('owner_id', $data->ownerId)
                        ->where('profile', $data->profile)
                        ->lockForUpdate()
                        ->first();

                    if ($assignment !== null
                        && $assignment->revision !== $data->expectedRevision) {
                        throw StaleTemplateException::forResource('template assignment', $assignment->id);
                    }

                    if ($assignment === null && $data->expectedRevision !== 0) {
                        throw StaleTemplateException::forResource('template assignment', 'new');
                    }

                    $assignment ??= new TemplateAssignment;
                    $assignment->fill([
                        'template_id' => $template->id,
                        'template_version_id' => $data->versionId,
                        'owner_type' => $data->ownerType,
                        'owner_id' => $data->ownerId,
                        'profile' => $data->profile,
                        'settings' => $data->settings,
                    ])->save();
                    TemplateChanged::dispatch($template->id, 'assigned', $actor);

                    return $assignment->refresh();
                });
        } catch (UniqueConstraintViolationException $exception) {
            $assignment = TemplateAssignment::query()
                ->where('owner_type', $data->ownerType)
                ->where('owner_id', $data->ownerId)
                ->where('profile', $data->profile)
                ->first();

            if ($assignment === null) {
                throw $exception;
            }

            throw StaleTemplateException::forResource(
                'template assignment',
                $assignment->id,
            );
        }
    }
}
