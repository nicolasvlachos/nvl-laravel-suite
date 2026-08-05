<?php

declare(strict_types=1);

namespace Nvl\Templates\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Templates\Contracts\TemplateAuthorization;
use Nvl\Templates\Data\TemplateActorData;
use Nvl\Templates\Enums\TemplateAbility;
use Nvl\Templates\Events\TemplateChanged;
use Nvl\Templates\Exceptions\StaleTemplateException;
use Nvl\Templates\Models\TemplateAssignment;
use Nvl\Templates\Support\TemplatesConfiguration;

/**
 * Deletes one assignment with exact optimistic concurrency.
 */
final readonly class UnassignTemplateAction
{
    public function __construct(private TemplateAuthorization $authorization) {}

    public function execute(
        TemplateAssignment|string $assignment,
        int $expectedRevision,
        TemplateActorData $actor,
    ): bool {
        $assignmentId = $assignment instanceof TemplateAssignment
            ? $assignment->id
            : $assignment;

        return DB::connection(TemplatesConfiguration::connection())
            ->transaction(function () use (
                $actor,
                $assignmentId,
                $expectedRevision,
            ): bool {
                $assignment = TemplateAssignment::query()
                    ->lockForUpdate()
                    ->findOrFail($assignmentId);
                $this->authorization->authorize(
                    TemplateAbility::Assign,
                    $actor,
                    [
                        'template_id' => $assignment->template_id,
                        'owner_type' => $assignment->owner_type,
                        'owner_id' => $assignment->owner_id,
                        'profile' => $assignment->profile,
                        'version_id' => $assignment->template_version_id,
                        'operation' => 'unassign',
                    ],
                );

                if ($assignment->revision !== $expectedRevision) {
                    throw StaleTemplateException::forResource(
                        'template assignment',
                        $assignment->id,
                    );
                }

                $templateId = $assignment->template_id;
                $deleted = (bool) $assignment->delete();

                if ($deleted) {
                    TemplateChanged::dispatch($templateId, 'unassigned', $actor);
                }

                return $deleted;
            });
    }
}
