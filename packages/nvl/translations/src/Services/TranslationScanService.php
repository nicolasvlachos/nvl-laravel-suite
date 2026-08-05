<?php

declare(strict_types=1);

namespace Nvl\Translations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Nvl\Translations\Enums\TranslationFormat;
use Nvl\Translations\Enums\TranslationScopeType;
use Nvl\Translations\Exceptions\TranslationsException;
use Nvl\Translations\Models\TranslationScanRun;
use Nvl\Translations\Models\TranslationUsage;
use Nvl\Translations\Support\TranslationConfiguration;
use Nvl\Translations\Support\TranslationIdentity;

/**
 * Scans source files and records translation key usage hits.
 *
 * This stable scanning boundary owns the transaction that publishes one
 * complete scan run together with all usage updates.
 */
final class TranslationScanService
{
    /**
     * @param  TranslationScopeResolver  $scopeResolver  Scope resolver service
     */
    public function __construct(
        private readonly TranslationScopeResolver $scopeResolver,
        private readonly TranslationPathGuard $paths,
    ) {}

    /**
     * Scan the project and upsert usage hits.
     *
     * @return array{files:int,hits:int,scanned_at:CarbonImmutable}
     */
    public function execute(): array
    {
        $files = $this->discoverTargetFiles();
        $scannedAt = CarbonImmutable::now();
        $scanId = (string) Str::uuid();

        $hits = [];
        $patterns = $this->patterns();

        foreach ($files as $filePath) {
            $contents = File::get($filePath);

            foreach ($patterns as $pattern) {
                $this->collectHitsFromPattern($hits, $contents, $filePath, $pattern);
            }
        }

        $rows = [];
        foreach ($hits as $hit) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'scan_id' => $scanId,
                'identity_hash' => TranslationIdentity::usage(
                    $hit['scope_type'],
                    $hit['scope_name'],
                    $hit['format'],
                    $hit['full_key'],
                    $hit['file_path'],
                    $hit['line'],
                ),
                'scope_type' => $hit['scope_type'],
                'scope_name' => $hit['scope_name'],
                'format' => $hit['format'],
                'full_key' => $hit['full_key'],
                'file_path' => $hit['file_path'],
                'line' => $hit['line'],
                'last_seen_at' => $scannedAt,
                'created_at' => $scannedAt,
                'updated_at' => $scannedAt,
            ];
        }

        DB::transaction(function () use ($rows, $scanId, $scannedAt, $files): void {
            foreach (array_chunk($rows, 1000) as $chunk) {
                TranslationUsage::query()->upsert(
                    $chunk,
                    ['identity_hash'],
                    [
                        'scan_id',
                        'scope_type',
                        'scope_name',
                        'format',
                        'full_key',
                        'file_path',
                        'line',
                        'last_seen_at',
                        'updated_at',
                    ],
                );
            }

            $scanRun = new TranslationScanRun;
            $scanRun->id = $scanId;
            $scanRun->scanned_at = $scannedAt;
            $scanRun->files = count($files);
            $scanRun->hits = count($rows);
            $scanRun->save();

            $retentionDays = TranslationConfiguration::nonNegativeInteger(
                'translations.scan.retention_days',
                30,
            );

            if ($retentionDays > 0) {
                TranslationUsage::query()
                    ->where('last_seen_at', '<', $scannedAt->subDays($retentionDays))
                    ->delete();
                TranslationScanRun::query()
                    ->where('scanned_at', '<', $scannedAt->subDays($retentionDays))
                    ->delete();
            }
        });

        return [
            'files' => count($files),
            'hits' => count($rows),
            'scanned_at' => $scannedAt,
        ];
    }

    /**
     * Validate scanner paths, extensions, and regular expressions without scanning files.
     */
    public function validateConfiguration(): void
    {
        $this->scanRoots();
        $this->extensions();
        $this->patterns();
        $this->scopeResolver->validateNamespaceConfiguration();
        TranslationConfiguration::nonNegativeInteger('translations.scan.retention_days', 30);
    }

    /**
     * @return list<string>
     */
    private function discoverTargetFiles(): array
    {
        $targets = [];
        $extensions = $this->extensions();

        foreach ($this->scanRoots() as $root) {
            if (! File::isDirectory($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                $relativePath = str_replace('\\', '/', $file->getRelativePathname());
                $safePath = $this->paths->child($root, $relativePath);
                $pathname = str_replace('\\', '/', $safePath);
                $matches = false;

                foreach ($extensions as $extension) {
                    if (str_ends_with($pathname, '.'.ltrim($extension, '.'))) {
                        $matches = true;

                        break;
                    }
                }

                if (! $matches) {
                    continue;
                }

                $targets[$pathname] = $safePath;
            }
        }

        sort($targets);

        return $targets;
    }

    /**
     * @param  array<string, array{scope_type:string|null,scope_name:string|null,format:string,full_key:string,file_path:string,line:int}>  $hits
     */
    private function collectHitsFromPattern(array &$hits, string $contents, string $filePath, string $pattern): void
    {
        $matches = [];
        $matched = preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE);

        if ($matched === false) {
            throw new TranslationsException("Invalid translation scanner pattern [{$pattern}].");
        }

        if (! isset($matches[1])) {
            return;
        }

        $lastOffset = 0;
        $line = 1;

        foreach ($matches[1] as $item) {
            $rawKey = $item[0];
            $offset = $item[1];

            $line += substr_count($contents, "\n", $lastOffset, max(0, $offset - $lastOffset));
            $lastOffset = $offset;

            if (! mb_check_encoding($rawKey, 'UTF-8')) {
                continue;
            }

            $parsed = $this->parseUsageKey($rawKey);
            if ($parsed === null) {
                continue;
            }

            $relativePath = $this->displayPath($filePath);
            $uniqueKey = TranslationIdentity::usage(
                $parsed['scope_type'],
                $parsed['scope_name'],
                $parsed['format'],
                $parsed['full_key'],
                $relativePath,
                $line,
            );

            $hits[$uniqueKey] = [
                'scope_type' => $parsed['scope_type'],
                'scope_name' => $parsed['scope_name'],
                'format' => $parsed['format'],
                'full_key' => $parsed['full_key'],
                'file_path' => $relativePath,
                'line' => $line,
            ];
        }
    }

    /**
     * @return array{scope_type:string|null,scope_name:string|null,format:string,full_key:string}|null
     */
    private function parseUsageKey(string $rawKey): ?array
    {
        $key = trim($rawKey);
        if ($key === '' || str_contains($key, '$')) {
            return null;
        }

        $scopeType = TranslationScopeType::App->value;
        $scopeName = 'app';
        $fullKey = $key;
        $namespaced = false;

        if (str_contains($key, '::')) {
            [$namespace, $rest] = array_pad(explode('::', $key, 2), 2, '');
            $namespace = trim($namespace);
            $rest = trim($rest);
            if ($rest === '') {
                return null;
            }

            $scope = $this->scopeResolver->resolveNamespace($namespace);
            if ($scope === null) {
                return null;
            }

            $scopeType = $scope->type->value;
            $scopeName = $scope->name;
            $fullKey = $rest;
            $namespaced = true;
        }

        $format = $this->detectFormat($fullKey, $namespaced)->value;

        return [
            'scope_type' => $scopeType,
            'scope_name' => $scopeName,
            'format' => $format,
            'full_key' => $fullKey,
        ];
    }

    private function detectFormat(string $fullKey, bool $namespaced): TranslationFormat
    {
        if ($namespaced) {
            return TranslationFormat::Php;
        }

        if (str_contains($fullKey, ' ')) {
            return TranslationFormat::Json;
        }

        return preg_match('/^[A-Za-z0-9_\/-]+\.[A-Za-z0-9_.\/-]+$/', $fullKey) === 1
            ? TranslationFormat::Php
            : TranslationFormat::Json;
    }

    /**
     * Return configured literal-key scanner patterns.
     *
     * @return list<string>
     */
    private function patterns(): array
    {
        $configured = config('translations.scan.patterns', []);

        if (! is_array($configured)) {
            throw new TranslationsException('translations.scan.patterns must be a list of regular expressions.');
        }

        $patterns = array_values(array_filter(
            $configured,
            static fn (mixed $pattern): bool => is_string($pattern) && trim($pattern) !== '',
        ));

        if ($patterns === []) {
            throw new TranslationsException('translations.scan.patterns must contain at least one pattern.');
        }

        foreach ($patterns as $pattern) {
            if (@preg_match($pattern, '') === false) {
                throw new TranslationsException("Invalid translation scanner pattern [{$pattern}].");
            }
        }

        return $patterns;
    }

    /**
     * Return normalized, validated source extensions.
     *
     * @return list<string>
     */
    private function extensions(): array
    {
        $configured = config('translations.scan.extensions', []);

        if (! is_array($configured)) {
            throw new TranslationsException('translations.scan.extensions must be a list of file extensions.');
        }

        $extensions = [];

        foreach ($configured as $extension) {
            if (! is_string($extension)) {
                throw new TranslationsException('Every translation scanner extension must be a string.');
            }

            $normalized = ltrim(trim($extension), '.');

            if ($normalized === ''
                || mb_strlen($normalized) > 32
                || preg_match('/^[A-Za-z0-9.]+$/', $normalized) !== 1) {
                throw new TranslationsException("Invalid translation scanner extension [{$extension}].");
            }

            $extensions[] = $normalized;
        }

        if ($extensions === []) {
            throw new TranslationsException('translations.scan.extensions must contain at least one extension.');
        }

        return array_values(array_unique($extensions));
    }

    /**
     * Return validated scanner roots, including roots that do not exist yet.
     *
     * @return list<string>
     */
    private function scanRoots(): array
    {
        $configured = config('translations.scan.paths', []);

        if (! is_array($configured)) {
            throw new TranslationsException('translations.scan.paths must be a list of absolute directories.');
        }

        $roots = [];

        foreach ($configured as $path) {
            if (! is_string($path) || trim($path) === '') {
                throw new TranslationsException(
                    'Every translations.scan.paths item must be a non-empty absolute path.',
                );
            }

            $roots[] = $this->paths->root($path);
        }

        return array_values(array_unique($roots));
    }

    /**
     * Return a stable project-relative path when the scanned file is inside the application.
     */
    private function displayPath(string $filePath): string
    {
        $normalizedPath = str_replace('\\', '/', $filePath);
        $normalizedBase = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        return str_starts_with($normalizedPath, $normalizedBase)
            ? substr($normalizedPath, strlen($normalizedBase))
            : $normalizedPath;
    }
}
