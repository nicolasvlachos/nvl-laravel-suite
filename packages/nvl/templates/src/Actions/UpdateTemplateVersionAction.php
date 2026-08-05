<?php

declare(strict_types=1);

namespace Nvl\Templates\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Templates\Contracts\TemplateAuthorization;
use Nvl\Templates\Data\Mutations\UpdateTemplateVersionData;
use Nvl\Templates\Data\TemplateActorData;
use Nvl\Templates\Enums\TemplateAbility;
use Nvl\Templates\Enums\TemplateVersionStatus;
use Nvl\Templates\Events\TemplateChanged;
use Nvl\Templates\Exceptions\StaleTemplateException;
use Nvl\Templates\Models\TemplateVersion;
use Nvl\Templates\Services\TemplateContentGuard;
use Nvl\Templates\Support\TemplatesConfiguration;

/**
 * Replaces draft content while published and retired versions stay immutable.
 */
final readonly class UpdateTemplateVersionAction
{
    public function __construct(
        private TemplateAuthorization $authorization,
        private TemplateContentGuard $guard,
    ) {}

    public function execute(
        TemplateVersion|string $version,
        UpdateTemplateVersionData $data,
        TemplateActorData $actor,
    ): TemplateVersion {
        $versionId = $version instanceof TemplateVersion ? $version->id : $version;
        $this->authorization->authorize(
            TemplateAbility::Update,
            $actor,
            ['version_id' => $versionId],
        );
        $this->guard->metadata($data->metadata);

        return DB::connection(TemplatesConfiguration::connection())
            ->transaction(function () use ($actor, $data, $versionId): TemplateVersion {
                $version = TemplateVersion::query()->lockForUpdate()->findOrFail($versionId);

                if ($version->revision !== $data->expectedRevision) {
                    throw StaleTemplateException::forResource('template version', $version->id);
                }

                if ($version->status !== TemplateVersionStatus::Draft) {
                    throw new InvalidArgumentException(
                        'Published and retired template versions are immutable.',
                    );
                }

                $version->fill([
                    'metadata' => $data->metadata,
                    'content_snapshot' => null,
                    'content_hash' => null,
                ])->save();
                TemplateChanged::dispatch($version->template_id, 'version_updated', $actor);

                return $version->refresh();
            });
    }
}
