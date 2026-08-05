<?php

declare(strict_types=1);

namespace Nvl\Translations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Nvl\Translations\Exceptions\TranslationsException;
use Throwable;

/**
 * Applies a validated translation artifact batch with backups and best-effort rollback.
 *
 * This stable file-writing boundary owns sibling staging and rollback because
 * its batch guarantee cannot be delegated to a database transaction.
 *
 * @phpstan-type ArtifactWrite array{path:string,content:string,target_root:string}
 * @phpstan-type ArtifactDelete array{path:string,target_root:string}
 * @phpstan-type OriginalFile array{exists:bool,content:string|null,permissions:int|null}
 */
final class TranslationArtifactWriter
{
    public function __construct(
        private readonly TranslationPathGuard $paths,
    ) {}

    /**
     * Apply every write and deletion only after all replacement files are staged.
     *
     * @param  list<ArtifactWrite>  $writes
     * @param  list<ArtifactDelete>  $deletes
     */
    public function apply(array $writes, array $deletes): void
    {
        $this->validatePlan($writes, $deletes);
        $originals = $this->captureOriginals($writes, $deletes);
        $temporaryPaths = [];
        $backupBatch = CarbonImmutable::now()->format('Ymd_His_u').'-'.bin2hex(random_bytes(4));
        $mutatedPaths = [];

        try {
            foreach ($writes as $write) {
                $temporaryPaths[$write['path']] = $this->stage(
                    $write['path'],
                    $write['content'],
                );
            }

            foreach ([...$writes, ...$deletes] as $operation) {
                $this->backup($operation['path'], $operation['target_root'], $backupBatch);
            }

            foreach ($writes as $write) {
                $temporaryPath = $temporaryPaths[$write['path']];

                if (! @rename($temporaryPath, $write['path'])) {
                    throw new TranslationsException(
                        "Failed to atomically replace translation file [{$write['path']}].",
                    );
                }

                $mutatedPaths[$write['path']] = true;
                unset($temporaryPaths[$write['path']]);
            }

            foreach ($deletes as $delete) {
                if (! File::exists($delete['path'])) {
                    continue;
                }

                if (! File::delete($delete['path'])) {
                    throw new TranslationsException(
                        "Failed to delete translation file [{$delete['path']}].",
                    );
                }

                $mutatedPaths[$delete['path']] = true;
            }
        } catch (Throwable $exception) {
            foreach ($temporaryPaths as $temporaryPath) {
                File::delete($temporaryPath);
            }

            if ($mutatedPaths !== []) {
                try {
                    $this->restoreOriginals(array_intersect_key($originals, $mutatedPaths));
                } catch (Throwable $rollbackException) {
                    throw new TranslationsException(
                        "Translation artifact batch failed and rollback was incomplete: {$rollbackException->getMessage()}",
                        previous: $exception,
                    );
                }
            }

            throw $exception;
        }
    }

    /**
     * Validate every planned artifact path and generated payload without mutation.
     *
     * @param  list<ArtifactWrite>  $writes
     * @param  list<ArtifactDelete>  $deletes
     */
    public function validatePlan(array $writes, array $deletes): void
    {
        $this->assertDistinctOperations($writes, $deletes);

        foreach ($writes as $write) {
            $this->validateArtifact($write['path'], $write['content']);
        }
    }

    /**
     * Ensure no artifact path is targeted more than once.
     *
     * @param  list<ArtifactWrite>  $writes
     * @param  list<ArtifactDelete>  $deletes
     */
    private function assertDistinctOperations(array $writes, array $deletes): void
    {
        $paths = [];

        foreach ([...$writes, ...$deletes] as $operation) {
            $path = $this->normalizedPath($operation['path']);
            $this->paths->assertWithinRoot($operation['target_root'], $operation['path']);
            $identity = mb_strtolower($path, 'UTF-8');

            if (isset($paths[$identity])) {
                throw new TranslationsException(
                    "Translation artifact path [{$operation['path']}] is targeted more than once.",
                );
            }

            $paths[$identity] = true;
        }
    }

    /**
     * Capture the exact pre-batch state of every affected path.
     *
     * @param  list<ArtifactWrite>  $writes
     * @param  list<ArtifactDelete>  $deletes
     * @return array<string, OriginalFile>
     */
    private function captureOriginals(array $writes, array $deletes): array
    {
        $originals = [];

        foreach ([...$writes, ...$deletes] as $operation) {
            $path = $operation['path'];
            $exists = File::exists($path);

            if ($exists && ! File::isFile($path)) {
                throw new TranslationsException(
                    "Translation artifact path [{$path}] is not a regular file.",
                );
            }

            $permissions = $exists ? fileperms($path) : false;
            $originals[$path] = [
                'exists' => $exists,
                'content' => $exists ? (string) File::get($path) : null,
                'permissions' => $permissions === false ? null : $permissions & 0777,
            ];
        }

        return $originals;
    }

    private function stage(string $path, string $content): string
    {
        File::ensureDirectoryExists(dirname($path));
        $temporaryPath = $path.'.tmp.'.bin2hex(random_bytes(8));
        $permissions = File::exists($path) ? fileperms($path) : false;
        $handle = @fopen($temporaryPath, 'xb');

        if ($handle === false) {
            throw new TranslationsException("Failed to write temporary translation file [{$temporaryPath}].");
        }

        $writeException = null;

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new TranslationsException(
                    "Failed to lock temporary translation file [{$temporaryPath}].",
                );
            }

            $offset = 0;
            $length = strlen($content);

            while ($offset < $length) {
                $written = fwrite($handle, substr($content, $offset));

                if ($written === false || $written === 0) {
                    throw new TranslationsException(
                        "Failed to write temporary translation file [{$temporaryPath}].",
                    );
                }

                $offset += $written;
            }

            if (! fflush($handle)) {
                throw new TranslationsException(
                    "Failed to flush temporary translation file [{$temporaryPath}].",
                );
            }

            if (function_exists('fsync') && ! fsync($handle)) {
                throw new TranslationsException(
                    "Failed to synchronize temporary translation file [{$temporaryPath}].",
                );
            }

            flock($handle, LOCK_UN);
        } catch (Throwable $exception) {
            $writeException = $exception;
        } finally {
            fclose($handle);
        }

        if ($writeException instanceof Throwable) {
            File::delete($temporaryPath);

            throw $writeException;
        }

        if ($permissions !== false) {
            @chmod($temporaryPath, $permissions & 0777);
        }

        try {
            $this->validateArtifact($path, $content);
        } catch (Throwable $exception) {
            File::delete($temporaryPath);

            throw $exception;
        }

        return $temporaryPath;
    }

    private function validateArtifact(string $path, string $content): void
    {
        if (str_ends_with($path, '.json')) {
            json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            return;
        }

        if (str_ends_with($path, '.php')) {
            $tokens = token_get_all($content, TOKEN_PARSE);
            unset($tokens);
        }
    }

    private function backup(string $path, string $targetRoot, string $batch): void
    {
        if (! (bool) config('translations.backup.enabled', true)
            || ! File::exists($path)) {
            return;
        }

        $configuredDirectory = config(
            'translations.backup.directory',
            storage_path('translations/backups'),
        );

        if (! is_string($configuredDirectory) || trim($configuredDirectory) === '') {
            throw new TranslationsException(
                'translations.backup.directory must be an absolute directory.',
            );
        }

        $backupRoot = $this->paths->root($configuredDirectory);
        $relative = $this->paths->relativeToRoot($targetRoot, $path);
        $backupPath = $this->paths->child(
            $backupRoot,
            $batch,
            hash('sha256', $targetRoot),
            $relative,
        );

        File::ensureDirectoryExists(dirname($backupPath));

        if (! File::copy($path, $backupPath)) {
            throw new TranslationsException(
                "Failed to back up translation file [{$path}].",
            );
        }
    }

    /**
     * Restore files to their exact pre-batch contents.
     *
     * @param  array<string, OriginalFile>  $originals
     */
    private function restoreOriginals(array $originals): void
    {
        foreach ($originals as $path => $original) {
            if (! $original['exists']) {
                if (File::exists($path) && ! File::delete($path)) {
                    throw new TranslationsException(
                        "Failed to remove new translation file [{$path}] during rollback.",
                    );
                }

                continue;
            }

            $content = $original['content'] ?? '';
            $temporaryPath = $path.'.rollback.'.bin2hex(random_bytes(8));
            File::ensureDirectoryExists(dirname($path));

            if (File::put($temporaryPath, $content, true) === false
                || ! @rename($temporaryPath, $path)) {
                File::delete($temporaryPath);

                throw new TranslationsException(
                    "Failed to restore translation file [{$path}] during rollback.",
                );
            }

            if ($original['permissions'] !== null) {
                @chmod($path, $original['permissions']);
            }
        }
    }

    private function normalizedPath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
