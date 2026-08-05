<?php

declare(strict_types=1);

namespace Nvl\Translations\Actions\Sync;

use Nvl\Translations\Contracts\ImportTranslationsContract;
use Nvl\Translations\Events\TranslationsImported;
use Nvl\Translations\Services\TranslationImportService;
use Nvl\Translations\Services\TranslationProcessLock;

/**
 * Runs translation import synchronization.
 */
final class ImportTranslationsAction implements ImportTranslationsContract
{
    /**
     * @param  TranslationImportService  $importService  Import service
     */
    public function __construct(
        private readonly TranslationImportService $importService,
        private readonly TranslationProcessLock $lock,
    ) {}

    /**
     * @param  list<string>  $scopeTokens
     * @return array{scopes:int,files:int,entries:int,created:int,updated:int,preserved:int,conflicts:int,missing:int,warnings:list<string>}
     */
    public function execute(
        array $scopeTokens = [],
        string $format = 'both',
        bool $dryRun = false,
    ): array {
        $result = $this->lock->execute(
            'sync',
            fn (): array => $this->importService->execute($scopeTokens, $format, $dryRun),
        );

        if (! $dryRun) {
            event(new TranslationsImported($result));
        }

        return $result;
    }
}
