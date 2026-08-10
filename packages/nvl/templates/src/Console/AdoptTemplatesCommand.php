<?php

declare(strict_types=1);

namespace Nvl\Templates\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use Nvl\Templates\Actions\AdoptTemplatesAction;

/**
 * Plans, prepares, and applies a restart-safe Templates/Content adoption manifest.
 */
final class AdoptTemplatesCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:templates:adopt
        {manifest : Absolute or application-relative JSON manifest path}
        {--prepare : Drop collision-prone non-primary indexes from declared staging tables}
        {--apply : Apply idempotent Template and Content writes and register Media aliases}
        {--format=text : Output text or json}';

    /** @var string */
    protected $description = 'Plan or apply staged adoption into NVL Templates, Content, and Media';

    public function handle(AdoptTemplatesAction $adopt): int
    {
        $format = $this->option('format');

        if (! in_array($format, ['text', 'json'], true)) {
            throw new InvalidArgumentException(
                'The nvl:templates:adopt format must be text or json.',
            );
        }

        $manifestPath = $this->argument('manifest');

        if (trim($manifestPath) === '') {
            throw new InvalidArgumentException('The adoption manifest path is required.');
        }

        $path = str_starts_with($manifestPath, DIRECTORY_SEPARATOR)
            ? $manifestPath
            : base_path($manifestPath);
        $maximumBytes = config('templates.adoption.maximum_manifest_bytes', 1_048_576);

        if (! is_int($maximumBytes) || $maximumBytes < 1) {
            throw new InvalidArgumentException(
                'templates.adoption.maximum_manifest_bytes must be a positive integer.',
            );
        }

        $size = is_file($path) ? filesize($path) : false;

        if (! is_int($size) || $size > $maximumBytes) {
            throw new InvalidArgumentException(
                "Adoption manifest [{$path}] is missing or exceeds {$maximumBytes} bytes.",
            );
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new InvalidArgumentException("Adoption manifest [{$path}] cannot be read.");
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                "Adoption manifest [{$path}] is not valid JSON.",
                previous: $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('The adoption manifest must be a JSON object.');
        }

        /** @var array<array-key, mixed> $decoded */
        $result = $adopt->execute(
            $decoded,
            prepare: (bool) $this->option('prepare'),
            apply: (bool) $this->option('apply'),
        );

        if ($format === 'json') {
            $this->line((string) json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            ));
        } else {
            $this->components->info($result['mode'] === 'plan'
                ? 'Adoption plan is valid; no data was changed.'
                : 'Adoption phase completed and reconciled.');
            $reconciliation = $result['reconciliation'] ?? null;

            if (! is_array($reconciliation)) {
                throw new InvalidArgumentException('Adoption reconciliation output is invalid.');
            }

            $rows = [];

            foreach ($reconciliation as $resource => $counts) {
                if (! is_string($resource) || ! is_array($counts)) {
                    throw new InvalidArgumentException('Adoption reconciliation output is invalid.');
                }

                $values = [];

                foreach (['expected', 'matched', 'created', 'updated', 'unchanged'] as $key) {
                    $value = $counts[$key] ?? null;

                    if (! is_int($value)) {
                        throw new InvalidArgumentException(
                            'Adoption reconciliation counts must be integers.',
                        );
                    }

                    $values[] = $value;
                }

                $rows[] = [$resource, ...$values];
            }

            $this->table(
                ['Resource', 'Expected', 'Matched', 'Created', 'Updated', 'Unchanged'],
                $rows,
            );
        }

        return self::SUCCESS;
    }
}
