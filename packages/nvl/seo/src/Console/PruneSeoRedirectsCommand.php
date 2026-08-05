<?php

declare(strict_types=1);

namespace Nvl\Seo\Console;

use Illuminate\Console\Command;
use Nvl\Seo\Actions\PruneSeoRedirectsAction;

/**
 * Prunes soft-deleted redirects outside the configured retention window.
 */
final class PruneSeoRedirectsCommand extends Command
{
    protected $signature = 'nvl:seo:redirects:prune
        {--days=30 : Retain soft-deleted redirects for this many days}';

    protected $description = 'Permanently remove old soft-deleted SEO redirects';

    /**
     * Prune retained redirect tombstones.
     */
    public function handle(PruneSeoRedirectsAction $action): int
    {
        $days = $this->option('days');

        if (! is_string($days)
            || preg_match('/^[1-9][0-9]*$/', (string) $days) !== 1) {
            $this->components->error('The --days option must be a positive integer.');

            return self::INVALID;
        }

        $pruned = $action->execute((int) $days);
        $this->components->info("Pruned {$pruned} soft-deleted SEO redirect(s).");

        return self::SUCCESS;
    }
}
