<?php

declare(strict_types=1);

namespace Nvl\Content\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Nvl\Content\Content;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentDefinitionMigrationProblemData;

/**
 * Plans or atomically applies one bounded definition migration batch.
 */
final class MigrateContentDefinitionsCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:content:definitions:migrate
        {--definition= : Restrict the batch to one definition key}
        {--limit= : Maximum blocks in this atomic batch}
        {--dry-run : Report the exact revision-safe plan without applying it}
        {--format=text : Output format: text or json}';

    /** @var string */
    protected $description = 'Plan or migrate stored blocks to their current content definition versions';

    public function handle(Content $content): int
    {
        $format = $this->option('format');
        $definition = $this->option('definition');
        $limit = $this->option('limit');

        if (! in_array($format, ['text', 'json'], true)) {
            throw new InvalidArgumentException(
                'The content definition migration format must be text or json.',
            );
        }

        if ($definition !== null && $definition === '') {
            throw new InvalidArgumentException(
                'The content definition migration key must be a non-empty string.',
            );
        }

        if ($limit !== null && preg_match('/^[1-9][0-9]*$/', $limit) !== 1) {
            throw new InvalidArgumentException(
                'The content definition migration limit must be a positive integer.',
            );
        }

        $actor = ContentActorData::system();
        $plan = $content->planDefinitionMigrations(
            actor: $actor,
            definition: $definition,
            limit: $limit !== null ? (int) $limit : null,
        );
        $result = null;

        if ($plan->blocked === [] && ! (bool) $this->option('dry-run')) {
            $result = $content->applyDefinitionMigrations($plan, $actor);
        }

        if ($format === 'json') {
            $this->line((string) json_encode([
                'plan' => $plan->toArray(),
                'result' => $result?->toArray(),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            foreach ($plan->ready as $target) {
                $this->line(
                    "ready: {$target->definition}:{$target->blockId} ".
                    "{$target->fromVersion}->{$target->toVersion} ".
                    "revision {$target->expectedRevision}",
                );
            }

            foreach ($plan->blocked as $problem) {
                $this->line($this->problem($problem));
            }

            if ($result !== null) {
                $this->info('migrated: '.count($result->migrated));
            }

            if ($plan->hasMore) {
                $this->warn('More pending blocks remain; run another batch after this one.');
            }
        }

        return $plan->blocked === [] ? self::SUCCESS : self::FAILURE;
    }

    private function problem(ContentDefinitionMigrationProblemData $problem): string
    {
        return "blocked: {$problem->definition}:{$problem->blockId} ".
            "{$problem->fromVersion}->{$problem->toVersion} ".
            "[{$problem->code}] {$problem->message}";
    }
}
