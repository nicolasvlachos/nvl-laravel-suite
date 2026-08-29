<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Content\ContentConsumerProbe;
use Illuminate\Console\Command;
use JsonException;

/** Runs the sealed Content production-consumer workflow. */
final class ContentConsumerSmokeCommand extends Command
{
    /** @var string */
    protected $signature = 'content-consumer:smoke
        {--verify-queue : Verify queued Media effects and clean stored files}
        {--format=table : Output table or json}';

    /** @var string */
    protected $description = 'Exercise Pages, Content, Media, SEO, Metafields, and translations';

    /** @throws JsonException */
    public function handle(ContentConsumerProbe $probe): int
    {
        $summary = $this->option('verify-queue')
            ? $probe->verifyQueueAndPrepareRollback()
            : $probe->run();

        if ($this->option('format') === 'json') {
            $this->line(json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(
            ['Metric', 'Value'],
            array_map(
                static fn (string $key, int|string|bool $value): array => [
                    $key,
                    is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
                ],
                array_keys($summary),
                array_values($summary),
            ),
        );

        return self::SUCCESS;
    }
}
