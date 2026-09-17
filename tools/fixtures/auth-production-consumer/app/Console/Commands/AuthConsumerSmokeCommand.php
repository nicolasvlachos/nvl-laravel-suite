<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\AuthConsumerSmoke;
use Illuminate\Console\Command;
use JsonException;
use LogicException;

/** Runs the sealed Auth production-consumer workflow. */
final class AuthConsumerSmokeCommand extends Command
{
    /** @var string */
    protected $signature = 'auth-consumer:smoke
        {--verify-queued-mail : Verify the database worker delivered the queued Mailable}
        {--tenant-smoke : Exercise the activated tenant ownership profile}
        {--format=table : Output table or json}';

    /** @var string */
    protected $description = 'Exercise the active sealed Auth consumer profile';

    /**
     * Execute the proof workflow.
     *
     * @throws JsonException
     */
    public function handle(AuthConsumerSmoke $smoke): int
    {
        $tenantProfile = config('tenancy.enabled') === true;
        if ((bool) $this->option('tenant-smoke') !== $tenantProfile) {
            throw new LogicException('The requested smoke mode does not match the configured Auth consumer profile.');
        }

        $summary = $smoke->execute((bool) $this->option('verify-queued-mail'));

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
