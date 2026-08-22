<?php

declare(strict_types=1);

namespace Nvl\Suite\Services;

use FilesystemIterator;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Nvl\Suite\Support\SuiteModuleCatalog;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Publishes and audits the agent skills owned by the installed Suite.
 *
 * @phpstan-type SkillFileMap array<string, string>
 * @phpstan-type SkillManifestEntry array{module: string, directory: string, version: string, files: SkillFileMap}
 * @phpstan-type SkillManifest array{schema_version: int, owner: string, suite_version: string, skills: array<string, SkillManifestEntry>}
 * @phpstan-type SkillPublicationResult array{module: string|null, skill: string, status: string, changed: bool, message: string}
 * @phpstan-type SkillPublicationReport array{owner: string, suite_version: string, manifest: string, healthy: bool, results: list<SkillPublicationResult>}
 * @phpstan-type SkillDoctorCheck array{module: string|null, skill: string, status: string, passed: bool, severity: 'error'|'warning', message: string}
 * @phpstan-type SkillDoctorReport array{owner: string, suite_version: string, manifest: string, strict: bool, healthy: bool, checks: list<SkillDoctorCheck>}
 */
final readonly class SuiteSkillManager
{
    public const int MANIFEST_SCHEMA_VERSION = 1;

    public const string OWNER = 'nvl/laravel-suite';

    public const string MANIFEST_FILE = '.nvl-suite-skills.json';

    public function __construct(
        private Filesystem $filesystem,
        private SuiteModuleCatalog $catalog,
        private string $suiteRoot,
        private string $applicationRoot,
        private string $suiteVersion,
    ) {}

    /**
     * Publish every effective module skill without overwriting unmanaged directories.
     *
     * @return SkillPublicationReport
     */
    public function publish(bool $force = false): array
    {
        try {
            $manifest = $this->readManifest();
        } catch (RuntimeException $exception) {
            return $this->publicationReport([
                $this->publicationResult(
                    module: null,
                    skill: 'manifest',
                    status: 'invalid',
                    changed: false,
                    message: $exception->getMessage(),
                ),
            ]);
        }

        $managedSkills = $manifest['skills'] ?? [];
        $results = [];

        foreach ($this->catalog->effectiveModules() as $module) {
            $skill = $this->skillName($module);
            $source = $this->sourcePath($module);
            $target = $this->targetPath($module);
            $managed = $managedSkills[$skill] ?? null;

            try {
                $sourceFiles = $this->hashTree($source);
                $targetExists = $this->filesystem->isDirectory($target) && ! is_link($target);

                if ($this->filesystem->exists($target) && ! $targetExists) {
                    $results[] = $this->publicationResult(
                        module: $module,
                        skill: $skill,
                        status: 'conflict',
                        changed: false,
                        message: 'The destination exists but is not a regular directory; it was not touched.',
                    );

                    continue;
                }

                if (! $targetExists) {
                    $this->replaceSkillDirectory($source, $target);
                    $managedSkills[$skill] = $this->manifestEntry($module, $sourceFiles);
                    $results[] = $this->publicationResult(
                        module: $module,
                        skill: $skill,
                        status: 'installed',
                        changed: true,
                        message: 'Installed and recorded as Suite-owned.',
                    );

                    continue;
                }

                $targetFiles = $this->hashTree($target);

                if ($managed === null) {
                    if ($targetFiles === $sourceFiles) {
                        $managedSkills[$skill] = $this->manifestEntry($module, $sourceFiles);
                        $results[] = $this->publicationResult(
                            module: $module,
                            skill: $skill,
                            status: 'adopted',
                            changed: true,
                            message: 'Recorded the byte-identical existing directory as Suite-owned.',
                        );
                    } else {
                        $results[] = $this->publicationResult(
                            module: $module,
                            skill: $skill,
                            status: 'conflict',
                            changed: false,
                            message: 'An unmanaged directory already uses this Suite skill name; it was not touched.',
                        );
                    }

                    continue;
                }

                if ($targetFiles !== $managed['files'] && $targetFiles !== $sourceFiles && ! $force) {
                    $results[] = $this->publicationResult(
                        module: $module,
                        skill: $skill,
                        status: 'conflict',
                        changed: false,
                        message: 'The Suite-owned directory has local changes; rerun with --force to replace it.',
                    );

                    continue;
                }

                if ($targetFiles === $sourceFiles) {
                    $status = $managed === $this->manifestEntry($module, $sourceFiles)
                        ? 'unchanged'
                        : 'adopted';
                    $managedSkills[$skill] = $this->manifestEntry($module, $sourceFiles);
                    $results[] = $this->publicationResult(
                        module: $module,
                        skill: $skill,
                        status: $status,
                        changed: $status !== 'unchanged',
                        message: $status === 'unchanged'
                            ? 'The managed skill is current.'
                            : 'Refreshed ownership metadata for the byte-identical skill.',
                    );

                    continue;
                }

                $this->replaceSkillDirectory($source, $target);
                $managedSkills[$skill] = $this->manifestEntry($module, $sourceFiles);
                $results[] = $this->publicationResult(
                    module: $module,
                    skill: $skill,
                    status: $targetFiles === $managed['files'] ? 'updated' : 'forced',
                    changed: true,
                    message: $targetFiles === $managed['files']
                        ? 'Updated the unmodified Suite-owned skill.'
                        : 'Replaced the locally modified Suite-owned skill.',
                );
            } catch (Throwable $throwable) {
                $results[] = $this->publicationResult(
                    module: $module,
                    skill: $skill,
                    status: 'error',
                    changed: false,
                    message: $throwable->getMessage(),
                );
            }
        }

        try {
            $this->writeManifest($managedSkills);
        } catch (Throwable $throwable) {
            $results[] = $this->publicationResult(
                module: null,
                skill: 'manifest',
                status: 'error',
                changed: false,
                message: $throwable->getMessage(),
            );
        }

        return $this->publicationReport($results);
    }

    /**
     * Compare installed managed skills with their manifest and package sources.
     *
     * This method performs no writes.
     *
     * @return SkillDoctorReport
     */
    public function inspect(bool $strict = false): array
    {
        try {
            $manifest = $this->readManifest();
        } catch (RuntimeException $exception) {
            return $this->doctorReport($strict, [
                $this->doctorCheck(
                    module: null,
                    skill: 'manifest',
                    status: 'invalid',
                    passed: false,
                    severity: 'error',
                    message: $exception->getMessage(),
                ),
            ]);
        }

        if ($manifest === null) {
            $checks = [
                $this->doctorCheck(
                    module: null,
                    skill: 'manifest',
                    status: 'missing',
                    passed: false,
                    severity: 'error',
                    message: 'The Suite skill ownership manifest has not been published.',
                ),
            ];

            foreach ($this->catalog->effectiveModules() as $module) {
                $targetExists = $this->filesystem->exists($this->targetPath($module));
                $checks[] = $this->doctorCheck(
                    module: $module,
                    skill: $this->skillName($module),
                    status: $targetExists ? 'unmanaged' : 'missing',
                    passed: false,
                    severity: 'error',
                    message: $targetExists
                        ? 'The enabled Suite skill exists without an ownership manifest.'
                        : 'The enabled Suite skill has not been published.',
                );
            }

            return $this->doctorReport($strict, $checks);
        }

        $checks = [
            $this->doctorCheck(
                module: null,
                skill: 'manifest',
                status: $manifest['suite_version'] === $this->suiteVersion ? 'current' : 'outdated',
                passed: $manifest['suite_version'] === $this->suiteVersion,
                severity: 'error',
                message: $manifest['suite_version'] === $this->suiteVersion
                    ? 'The manifest belongs to the installed Suite version.'
                    : sprintf(
                        'The manifest records Suite version [%s], but [%s] is installed.',
                        $manifest['suite_version'],
                        $this->suiteVersion,
                    ),
            ),
        ];
        $effectiveModules = $this->catalog->effectiveModules();

        foreach ($effectiveModules as $module) {
            $skill = $this->skillName($module);
            $managed = $manifest['skills'][$skill] ?? null;

            if ($managed === null) {
                $checks[] = $this->doctorCheck(
                    module: $module,
                    skill: $skill,
                    status: $this->filesystem->exists($this->targetPath($module)) ? 'unmanaged' : 'missing',
                    passed: false,
                    severity: 'error',
                    message: $this->filesystem->exists($this->targetPath($module))
                        ? 'The enabled Suite skill exists without Suite ownership metadata.'
                        : 'The enabled Suite skill has not been published.',
                );

                continue;
            }

            $target = $this->targetPath($module);

            if (! $this->filesystem->isDirectory($target) || is_link($target)) {
                $checks[] = $this->doctorCheck(
                    module: $module,
                    skill: $skill,
                    status: 'missing',
                    passed: false,
                    severity: 'error',
                    message: 'The manifest-owned Suite skill directory is missing or is not a regular directory.',
                );

                continue;
            }

            try {
                $sourceFiles = $this->hashTree($this->sourcePath($module));
                $targetFiles = $this->hashTree($target);
            } catch (Throwable $throwable) {
                $checks[] = $this->doctorCheck(
                    module: $module,
                    skill: $skill,
                    status: 'invalid',
                    passed: false,
                    severity: 'error',
                    message: $throwable->getMessage(),
                );

                continue;
            }

            $targetMatchesManifest = $targetFiles === $managed['files'];
            $sourceMatchesManifest = $sourceFiles === $managed['files'];
            $versionMatches = $managed['version'] === $this->suiteVersion;

            $checks[] = match (true) {
                ! $targetMatchesManifest => $this->doctorCheck(
                    module: $module,
                    skill: $skill,
                    status: 'modified',
                    passed: false,
                    severity: 'error',
                    message: 'The published Suite-owned skill differs from its ownership manifest.',
                ),
                ! $sourceMatchesManifest || ! $versionMatches => $this->doctorCheck(
                    module: $module,
                    skill: $skill,
                    status: 'outdated',
                    passed: false,
                    severity: 'error',
                    message: sprintf('The published skill is not current for Suite version [%s].', $this->suiteVersion),
                ),
                default => $this->doctorCheck(
                    module: $module,
                    skill: $skill,
                    status: 'current',
                    passed: true,
                    severity: 'error',
                    message: 'The enabled Suite skill is manifest-owned and current.',
                ),
            };
        }

        foreach ($manifest['skills'] as $skill => $managed) {
            if (in_array($managed['module'], $effectiveModules, true)) {
                continue;
            }

            $checks[] = $this->doctorCheck(
                module: $managed['module'],
                skill: $skill,
                status: 'disabled',
                passed: false,
                severity: 'warning',
                message: 'This Suite-owned skill remains installed for a module that is no longer enabled.',
            );
        }

        return $this->doctorReport($strict, $checks);
    }

    /**
     * Return the application-local manifest path.
     */
    public function manifestPath(): string
    {
        return $this->skillsRoot().'/'.self::MANIFEST_FILE;
    }

    /**
     * Return the canonical source for one module skill.
     */
    public function sourcePath(string $module): string
    {
        return $this->suiteRoot.'/resources/boost/skills/'.$this->skillName($module);
    }

    /**
     * @param  array<string, SkillManifestEntry>  $managedSkills
     */
    private function writeManifest(array $managedSkills): void
    {
        $this->assertManagedRootIsSafe();
        $this->filesystem->ensureDirectoryExists($this->skillsRoot());

        if (is_link($this->manifestPath())) {
            throw new RuntimeException('The Suite skill manifest is a symbolic link and was not replaced.');
        }

        ksort($managedSkills);
        $manifest = [
            'schema_version' => self::MANIFEST_SCHEMA_VERSION,
            'owner' => self::OWNER,
            'suite_version' => $this->suiteVersion,
            'skills' => $managedSkills,
        ];
        $encoded = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        $this->filesystem->replace($this->manifestPath(), $encoded.PHP_EOL);
    }

    /**
     * @return SkillManifest|null
     */
    private function readManifest(): ?array
    {
        $path = $this->manifestPath();

        if (is_link($path)) {
            throw new RuntimeException('The Suite skill ownership manifest is a symbolic link.');
        }

        if (! $this->filesystem->exists($path)) {
            return null;
        }

        if (! $this->filesystem->isFile($path)) {
            throw new RuntimeException('The Suite skill ownership manifest is not a regular file.');
        }

        try {
            $decoded = json_decode(
                $this->filesystem->get($path),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('The Suite skill ownership manifest is not valid JSON.', previous: $exception);
        }

        if (! is_array($decoded)
            || ($decoded['schema_version'] ?? null) !== self::MANIFEST_SCHEMA_VERSION
            || ($decoded['owner'] ?? null) !== self::OWNER
            || ! is_string($decoded['suite_version'] ?? null)
            || $decoded['suite_version'] === ''
            || ! is_array($decoded['skills'] ?? null)) {
            throw new RuntimeException('The Suite skill ownership manifest has an invalid schema or owner.');
        }

        $knownModules = array_keys($this->catalog->modules());
        $skills = [];

        foreach ($decoded['skills'] as $skill => $entry) {
            if (! is_string($skill)
                || ! is_array($entry)
                || ! is_string($entry['module'] ?? null)
                || ! in_array($entry['module'], $knownModules, true)
                || $skill !== $this->skillName($entry['module'])
                || ($entry['directory'] ?? null) !== $skill
                || ! is_string($entry['version'] ?? null)
                || $entry['version'] === ''
                || ! is_array($entry['files'] ?? null)
                || $entry['files'] === []) {
                throw new RuntimeException("The Suite skill manifest entry [{$skill}] is invalid.");
            }

            $files = [];

            foreach ($entry['files'] as $relativePath => $hash) {
                if (! is_string($relativePath)
                    || ! $this->isSafeRelativePath($relativePath)
                    || ! is_string($hash)
                    || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                    throw new RuntimeException("The Suite skill manifest file entry [{$skill}:{$relativePath}] is invalid.");
                }

                $files[$relativePath] = $hash;
            }

            ksort($files);
            $skills[$skill] = [
                'module' => $entry['module'],
                'directory' => $skill,
                'version' => $entry['version'],
                'files' => $files,
            ];
        }

        ksort($skills);

        return [
            'schema_version' => self::MANIFEST_SCHEMA_VERSION,
            'owner' => self::OWNER,
            'suite_version' => $decoded['suite_version'],
            'skills' => $skills,
        ];
    }

    /**
     * @return SkillFileMap
     */
    private function hashTree(string $directory): array
    {
        if (! $this->filesystem->isDirectory($directory) || is_link($directory)) {
            throw new RuntimeException("Skill directory [{$directory}] is missing or is not a regular directory.");
        }

        $this->assertTreeHasNoLinks($directory);
        $files = [];
        $prefixLength = mb_strlen(rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);

        foreach ($this->filesystem->allFiles($directory, hidden: true) as $file) {
            $relativePath = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                mb_substr($file->getPathname(), $prefixLength),
            );
            $hash = $this->filesystem->hash($file->getPathname(), 'sha256');

            if (! is_string($hash) || $hash === '') {
                throw new RuntimeException("Skill file [{$file->getPathname()}] could not be hashed.");
            }

            $files[$relativePath] = $hash;
        }

        if ($files === []) {
            throw new RuntimeException("Skill directory [{$directory}] is empty.");
        }

        ksort($files);

        return $files;
    }

    private function assertTreeHasNoLinks(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (! $item instanceof SplFileInfo) {
                continue;
            }

            if ($item->isLink()) {
                throw new RuntimeException("Skill tree [{$directory}] contains a symbolic link.");
            }
        }
    }

    private function replaceSkillDirectory(string $source, string $target): void
    {
        $this->assertManagedRootIsSafe();
        $this->filesystem->ensureDirectoryExists($this->skillsRoot());

        if (is_link($target)) {
            throw new RuntimeException("Skill destination [{$target}] is a symbolic link and was not replaced.");
        }

        $suffix = bin2hex(random_bytes(12));
        $staging = $this->skillsRoot().'/.nvl-suite-staging-'.$suffix;
        $backup = $this->skillsRoot().'/.nvl-suite-backup-'.$suffix;
        $targetMoved = false;
        $targetInstalled = false;

        try {
            if (! $this->filesystem->copyDirectory($source, $staging)) {
                throw new RuntimeException("Skill source [{$source}] could not be staged.");
            }

            if ($this->hashTree($staging) !== $this->hashTree($source)) {
                throw new RuntimeException("Staged skill [{$source}] failed its content verification.");
            }

            if ($this->filesystem->isDirectory($target)) {
                if (! $this->filesystem->moveDirectory($target, $backup)) {
                    throw new RuntimeException("Existing Suite-owned skill [{$target}] could not be backed up.");
                }

                $targetMoved = true;
            }

            if (! $this->filesystem->moveDirectory($staging, $target)) {
                throw new RuntimeException("Staged Suite skill [{$target}] could not be installed.");
            }

            $targetInstalled = true;
        } catch (Throwable $throwable) {
            if ($targetMoved && ! $this->filesystem->exists($target)) {
                $this->filesystem->moveDirectory($backup, $target);
            }

            throw $throwable;
        } finally {
            if ($this->filesystem->isDirectory($staging)) {
                $this->filesystem->deleteDirectory($staging);
            }

            if ($targetInstalled && $this->filesystem->isDirectory($backup)) {
                $this->filesystem->deleteDirectory($backup);
            }
        }
    }

    private function assertManagedRootIsSafe(): void
    {
        foreach ([$this->applicationRoot.'/.agents', $this->skillsRoot()] as $directory) {
            if (is_link($directory)) {
                throw new RuntimeException("Managed skill parent [{$directory}] is a symbolic link.");
            }

            if ($this->filesystem->exists($directory) && ! $this->filesystem->isDirectory($directory)) {
                throw new RuntimeException("Managed skill parent [{$directory}] is not a directory.");
            }
        }
    }

    private function isSafeRelativePath(string $path): bool
    {
        return $path !== ''
            && ! str_starts_with($path, '/')
            && ! str_contains($path, '\\')
            && ! str_contains($path, "\0")
            && preg_match('#(?:^|/)\.\.(?:/|$)#', $path) !== 1;
    }

    private function skillsRoot(): string
    {
        return $this->applicationRoot.'/.agents/skills';
    }

    private function targetPath(string $module): string
    {
        return $this->skillsRoot().'/'.$this->skillName($module);
    }

    private function skillName(string $module): string
    {
        return 'nvl-'.$module;
    }

    /**
     * @param  SkillFileMap  $files
     * @return SkillManifestEntry
     */
    private function manifestEntry(string $module, array $files): array
    {
        return [
            'module' => $module,
            'directory' => $this->skillName($module),
            'version' => $this->suiteVersion,
            'files' => $files,
        ];
    }

    /**
     * @return SkillPublicationResult
     */
    private function publicationResult(
        ?string $module,
        string $skill,
        string $status,
        bool $changed,
        string $message,
    ): array {
        return compact('module', 'skill', 'status', 'changed', 'message');
    }

    /**
     * @param  list<SkillPublicationResult>  $results
     * @return SkillPublicationReport
     */
    private function publicationReport(array $results): array
    {
        $failed = array_filter(
            $results,
            static fn (array $result): bool => in_array($result['status'], ['conflict', 'error', 'invalid'], true),
        );

        return [
            'owner' => self::OWNER,
            'suite_version' => $this->suiteVersion,
            'manifest' => $this->manifestPath(),
            'healthy' => $failed === [],
            'results' => $results,
        ];
    }

    /**
     * @param  'error'|'warning'  $severity
     * @return SkillDoctorCheck
     */
    private function doctorCheck(
        ?string $module,
        string $skill,
        string $status,
        bool $passed,
        string $severity,
        string $message,
    ): array {
        return compact('module', 'skill', 'status', 'passed', 'severity', 'message');
    }

    /**
     * @param  list<SkillDoctorCheck>  $checks
     * @return SkillDoctorReport
     */
    private function doctorReport(bool $strict, array $checks): array
    {
        $failed = array_filter(
            $checks,
            static fn (array $check): bool => ! $check['passed']
                && ($check['severity'] === 'error' || $strict),
        );

        return [
            'owner' => self::OWNER,
            'suite_version' => $this->suiteVersion,
            'manifest' => $this->manifestPath(),
            'strict' => $strict,
            'healthy' => $failed === [],
            'checks' => $checks,
        ];
    }
}
