<?php

declare(strict_types=1);

namespace Nvl\Translations\Contracts;

/**
 * Defines the authoritative-file to workspace synchronization entrypoint.
 */
interface ImportTranslationsContract
{
    /**
     * Import selected configured translation scopes.
     *
     * @param  list<string>  $scopeTokens
     * @return array{scopes:int,files:int,entries:int,created:int,updated:int,preserved:int,conflicts:int,missing:int,warnings:list<string>}
     */
    public function execute(
        array $scopeTokens = [],
        string $format = 'both',
        bool $dryRun = false,
    ): array;
}
