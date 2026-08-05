<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Comments\Probe\CommentsConsumerProbe;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

/**
 * Runs the representative Comments production-consumer rehearsal.
 */
final class CommentsConsumerSmokeCommand extends Command
{
    /** @var string */
    protected $signature = 'comments-consumer:smoke {--format=text : Output text or json}';

    /** @var string */
    protected $description = 'Exercise Comments targets, audiences, moderation, Media, and reconciliation.';

    /**
     * Execute the consumer rehearsal and return a shell-safe status.
     */
    public function handle(CommentsConsumerProbe $probe): int
    {
        try {
            $result = $probe->exercise();
        } catch (Throwable $exception) {
            return $this->renderFailure($exception);
        }

        if ($this->option('format') === 'json') {
            $this->line((string) json_encode(
                $result,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $this->info('Comments consumer smoke passed.');
        }

        return self::SUCCESS;
    }

    /**
     * Render one actionable command failure without losing JSON validity.
     */
    private function renderFailure(Throwable $exception): int
    {
        if ($this->option('format') === 'json') {
            try {
                $this->line((string) json_encode([
                    'ready' => false,
                    'error' => $exception->getMessage(),
                    'exception' => $exception::class,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } catch (JsonException) {
                $this->error('Comments consumer smoke failed and its diagnostic could not be encoded.');
            }
        } else {
            $this->error($exception->getMessage());
        }

        return self::FAILURE;
    }
}
