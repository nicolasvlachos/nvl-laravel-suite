<?php

declare(strict_types=1);

namespace Nvl\Templates\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Nvl\Templates\Data\TemplateDefinitionSyncData;
use Nvl\Templates\Enums\TemplateStatus;
use Nvl\Templates\Models\Template;
use Nvl\Templates\Services\CanonicalJson;
use Nvl\Templates\Services\TemplateDefinitionRegistry;
use Nvl\Templates\Support\TemplatesConfiguration;

/**
 * Atomically synchronizes source-controlled definitions and archives removed keys.
 */
final readonly class SyncTemplateDefinitionsAction
{
    public function __construct(private TemplateDefinitionRegistry $definitions) {}

    /**
     * Plan or apply one complete source-definition synchronization.
     *
     * @return Collection<int, TemplateDefinitionSyncData>
     */
    public function execute(bool $dryRun = false): Collection
    {
        return DB::connection(TemplatesConfiguration::connection())
            ->transaction(function () use ($dryRun): Collection {
                $canonicalJson = new CanonicalJson;
                $registered = $this->definitions->all();
                $registeredKeys = array_keys($registered);
                $stored = Template::query()
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('key');
                $plan = new Collection;

                foreach ($registered as $definition) {
                    $template = $stored->get($definition->key);
                    $operation = ! $template instanceof Template
                        ? 'create'
                        : ($template->renderer !== $definition->renderer
                            || $canonicalJson->digest($template->schema)
                                !== $canonicalJson->digest($definition->schema)
                            || $template->status === TemplateStatus::Archived
                                ? 'update'
                                : 'unchanged');
                    $plan->push(new TemplateDefinitionSyncData(
                        key: $definition->key,
                        operation: $operation,
                    ));

                    if ($dryRun || $operation === 'unchanged') {
                        continue;
                    }

                    $template ??= new Template;
                    $template->fill([
                        'key' => $definition->key,
                        'renderer' => $definition->renderer,
                        'status' => TemplateStatus::Active,
                        'schema' => $definition->schema,
                    ])->save();
                }

                foreach ($stored as $key => $template) {
                    if (in_array((string) $key, $registeredKeys, true)) {
                        continue;
                    }

                    $operation = $template->status === TemplateStatus::Archived
                        ? 'unchanged'
                        : 'archive';
                    $plan->push(new TemplateDefinitionSyncData(
                        key: $template->key,
                        operation: $operation,
                    ));

                    if (! $dryRun && $operation === 'archive') {
                        $template->fill([
                            'status' => TemplateStatus::Archived,
                        ])->save();
                    }
                }

                return $plan->values();
            });
    }
}
