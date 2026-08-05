<?php

declare(strict_types=1);

namespace Nvl\Translations\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Nvl\Translations\Models\TranslationEntry;
use Nvl\Translations\Services\TranslationScopeResolver;

/**
 * Reports configured roots and workspace synchronization state.
 */
final class TranslationsStatusCommand extends Command
{
    protected $signature = 'nvl:translations:status
        {--scope=* : Optional configured scope tokens}
        {--format=text : Output format: text or json}';

    protected $description = 'Report translation source profiles and workspace synchronization state';

    public function handle(TranslationScopeResolver $scopes): int
    {
        $format = $this->option('format');
        if (! is_string($format) || ! in_array($format, ['text', 'json'], true)) {
            $this->error('Invalid --format option. Allowed values: text, json.');

            return self::FAILURE;
        }

        $tokens = array_values(array_filter(
            (array) $this->option('scope'),
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));
        $resolved = $scopes->resolveScopes($tokens);
        $statusQuery = TranslationEntry::query();

        if ($tokens !== []) {
            $statusQuery->where(static function (Builder $builder) use ($resolved): void {
                foreach ($resolved as $scope) {
                    $builder->orWhere(static function (Builder $scopeQuery) use ($scope): void {
                        $scopeQuery
                            ->where('scope_type', $scope->type->value)
                            ->where('scope_name', $scope->name);
                    });
                }
            });
        }

        $statuses = $statusQuery
            ->selectRaw('sync_status, COUNT(*) AS aggregate')
            ->groupBy('sync_status')
            ->orderBy('sync_status')
            ->pluck('aggregate', 'sync_status')
            ->map(static fn (mixed $count): int => is_numeric($count) ? (int) $count : 0)
            ->all();
        $result = [
            'scopes' => array_map(
                static fn ($scope): array => [
                    'token' => $scope->token(),
                    'path' => $scope->path,
                ],
                $resolved,
            ),
            'statuses' => $statuses,
        ];

        if ($format === 'json') {
            $this->line((string) json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->table(['Scope', 'Path'], array_map(
                static fn (array $scope): array => [$scope['token'], $scope['path']],
                $result['scopes'],
            ));
            $this->table(['Status', 'Entries'], array_map(
                static fn (string $status, int $count): array => [$status, $count],
                array_keys($statuses),
                array_values($statuses),
            ));
        }

        return self::SUCCESS;
    }
}
