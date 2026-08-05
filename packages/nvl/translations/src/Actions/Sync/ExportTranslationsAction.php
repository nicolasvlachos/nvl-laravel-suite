<?php

declare(strict_types=1);

namespace Nvl\Translations\Actions\Sync;

use Illuminate\Support\Facades\DB;
use Nvl\Translations\Events\TranslationsExported;
use Nvl\Translations\Exceptions\TranslationsException;
use Nvl\Translations\Services\TranslationExportService;
use Nvl\Translations\Services\TranslationImportService;
use Nvl\Translations\Services\TranslationProcessLock;

/**
 * Runs translation export synchronization.
 */
final class ExportTranslationsAction
{
    /**
     * @param  TranslationExportService  $exportService  Export service
     */
    public function __construct(
        private readonly TranslationExportService $exportService,
        private readonly TranslationImportService $importService,
        private readonly TranslationProcessLock $lock,
    ) {}

    /**
     * @param  list<string>  $scopeTokens
     * @param  list<string>|null  $locales
     * @return array{scopes:int,locales:int,files:int,deleted:int,target:string}
     */
    public function execute(
        array $scopeTokens = [],
        ?array $locales = null,
        string $format = 'both',
        string $target = 'source',
        bool $prune = false,
        bool $dryRun = false,
    ): array {
        $result = $this->lock->execute(
            'export',
            function () use ($scopeTokens, $locales, $format, $target, $prune, $dryRun): array {
                if ($dryRun) {
                    DB::beginTransaction();

                    try {
                        $this->synchronizeAuthoritativeSource($scopeTokens, $format);

                        return $this->exportService->execute(
                            $scopeTokens,
                            $locales,
                            $format,
                            $target,
                            $prune,
                            true,
                        );
                    } finally {
                        DB::rollBack();
                    }
                }

                $this->synchronizeAuthoritativeSource($scopeTokens, $format);

                return $this->exportService->execute(
                    $scopeTokens,
                    $locales,
                    $format,
                    $target,
                    $prune,
                );
            },
        );

        if (! $dryRun) {
            event(new TranslationsExported($result));
        }

        return $result;
    }

    /**
     * Import authoritative files and refuse to export from an incomplete read.
     *
     * @param  list<string>  $scopeTokens
     *
     * @throws TranslationsException
     */
    private function synchronizeAuthoritativeSource(array $scopeTokens, string $format): void
    {
        $import = $this->importService->execute($scopeTokens, $format);

        if ($import['warnings'] === []) {
            return;
        }

        throw new TranslationsException(
            'Translation export stopped because the authoritative source read was incomplete: '.
            implode(' ', $import['warnings']),
        );
    }
}
