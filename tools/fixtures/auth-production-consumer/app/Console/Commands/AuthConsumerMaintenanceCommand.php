<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Auth\Enums\AuthMaintenanceTask;
use Nvl\Auth\Services\AuthMaintenanceTaskRunner;

/**
 * Executes every package-owned Auth maintenance task through its durable runner.
 */
final class AuthConsumerMaintenanceCommand extends Command
{
    protected $signature = 'auth-consumer:maintenance {--format=text : text or json}';

    protected $description = 'Bootstrap NVL Auth maintenance checkpoints in the production consumer';

    /**
     * Create the production-consumer maintenance command.
     */
    public function __construct(
        private readonly AuthMaintenanceTaskRunner $maintenance,
    ) {
        parent::__construct();
    }

    /**
     * Run every currently required maintenance task once.
     */
    public function handle(): int
    {
        $format = $this->option('format');

        if (! is_string($format) || ! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }

        $completed = [];

        foreach (AuthMaintenanceTask::cases() as $task) {
            $this->maintenance->run($task);
            $completed[] = $task->value;
        }

        $report = [
            'healthy' => count($completed) === count(AuthMaintenanceTask::cases()),
            'tasks' => $completed,
        ];

        if ($format === 'json') {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            ));
        } else {
            $this->line(sprintf(
                'Auth maintenance tasks completed: %d',
                count($completed),
            ));
        }

        return $report['healthy'] ? self::SUCCESS : self::FAILURE;
    }
}
