<?php

declare(strict_types=1);

namespace Nvl\Translations\Actions\Sync;

use Carbon\CarbonImmutable;
use Nvl\Translations\Contracts\ScanTranslationsContract;
use Nvl\Translations\Events\TranslationsScanned;
use Nvl\Translations\Services\TranslationProcessLock;
use Nvl\Translations\Services\TranslationScanService;

/**
 * Runs translation usage scanner.
 */
final class ScanTranslationsAction implements ScanTranslationsContract
{
    /**
     * @param  TranslationScanService  $scanService  Scanner service
     */
    public function __construct(
        private readonly TranslationScanService $scanService,
        private readonly TranslationProcessLock $lock,
    ) {}

    /**
     * @return array{files:int,hits:int,scanned_at:CarbonImmutable}
     */
    public function execute(): array
    {
        $result = $this->lock->execute(
            'scan',
            fn (): array => $this->scanService->execute(),
        );
        event(new TranslationsScanned($result));

        return $result;
    }
}
