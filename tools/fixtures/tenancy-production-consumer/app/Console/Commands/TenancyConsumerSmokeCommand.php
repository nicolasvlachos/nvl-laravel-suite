<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\TenancyConsumerWorkflow;
use Illuminate\Console\Command;
use JsonException;

/** Runs one bounded phase of the sealed tenancy production consumer. */
final class TenancyConsumerSmokeCommand extends Command
{
    /** @var string */
    protected $signature = 'tenancy-consumer:smoke
        {--phase=seed : seed|verify}
        {--format=json : Output json or table}';

    /** @var string */
    protected $description = 'Exercise Auth and Media tenant isolation from a sealed consumer';

    /**
     * Execute the requested seed or verification phase.
     *
     * @throws JsonException
     */
    public function handle(TenancyConsumerWorkflow $workflow): int
    {
        $phase = $this->option('phase');
        if (! is_string($phase)) {
            return self::INVALID;
        }
        $result = $workflow->execute($phase);

        if ($this->option('format') === 'json') {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->table(
                ['Check', 'Passed'],
                array_map(
                    static fn (string $check, bool $passed): array => [$check, $passed ? 'yes' : 'no'],
                    array_keys($result['checks']),
                    array_values($result['checks']),
                ),
            );
        }

        return $result['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
