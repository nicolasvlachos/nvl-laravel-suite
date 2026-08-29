<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\AuthConsumerProbe;
use Illuminate\Console\Command;
use JsonException;

/** Runs the sealed Auth production-consumer workflow. */
final class AuthConsumerSmokeCommand extends Command
{
    /** @var string */
    protected $signature = 'auth-consumer:smoke
        {--verify-queued-mail : Verify the database worker delivered the queued Mailable}
        {--format=table : Output table or json}';

    /** @var string */
    protected $description = 'Exercise Auth, Settings, Activity, and Mail Notifications';

    /**
     * Execute the proof workflow.
     *
     * @throws JsonException
     */
    public function handle(AuthConsumerProbe $probe): int
    {
        $summary = $this->option('verify-queued-mail')
            ? $probe->verifyQueuedMail()
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
