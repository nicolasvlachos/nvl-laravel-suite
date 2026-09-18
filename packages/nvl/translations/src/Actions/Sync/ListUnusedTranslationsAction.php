<?php

declare(strict_types=1);

namespace Nvl\Translations\Actions\Sync;

use Carbon\CarbonImmutable;
use Nvl\Translations\Services\SourceTranslationWorkspace;
use Nvl\Translations\Services\TranslationUnusedService;

/**
 * Builds unused translation reports.
 */
final class ListUnusedTranslationsAction
{
    /**
     * @param  TranslationUnusedService  $unusedService  Unused report service
     */
    public function __construct(
        private readonly TranslationUnusedService $unusedService,
        private readonly SourceTranslationWorkspace $workspace,
    ) {}

    /**
     * @param  list<string>  $scopeTokens
     * @return array{scanned_at:CarbonImmutable|null,total:int,rows:list<array{id:string,scope_type:string,scope_name:string,locale:string,format:string,group:string|null,key:string,full_key:string}>}
     */
    public function execute(array $scopeTokens = [], int $days = 0): array
    {
        $this->workspace->authorize();

        return $this->unusedService->execute($scopeTokens, $days);
    }
}
