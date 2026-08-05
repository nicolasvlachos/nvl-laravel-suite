<?php

declare(strict_types=1);

namespace Nvl\Templates\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Nvl\Templates\Actions\RecoverStaleTemplateRendersAction;

/**
 * Recovers durable renders stalled before or during queue processing.
 */
final class RecoverTemplateRendersCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:templates:renders:recover {--format=text}';

    /** @var string */
    protected $description = 'Requeue stalled pending renders and expired processing leases';

    /**
     * Recover and report one configured batch of stale renders.
     */
    public function handle(RecoverStaleTemplateRendersAction $action): int
    {
        $format = $this->option('format');

        if (! is_string($format) || ! in_array($format, ['text', 'json'], true)) {
            throw new InvalidArgumentException(
                'The render recovery format must be text or json.',
            );
        }

        $renders = $action->execute();
        $renderIds = array_map('strval', $renders->modelKeys());

        if ($format === 'json') {
            $this->line((string) json_encode([
                'recovered' => count($renderIds),
                'render_ids' => $renderIds,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->info('Recovered '.count($renderIds).' stale template renders.');
        }

        return self::SUCCESS;
    }
}
