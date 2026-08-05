<?php

declare(strict_types=1);

namespace Nvl\Translations\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Reports a completed authoritative-file import.
 */
final class TranslationsImported implements ShouldDispatchAfterCommit
{
    /**
     * @param  array{scopes:int,files:int,entries:int,created:int,updated:int,preserved:int,conflicts:int,missing:int,warnings:list<string>}  $result
     */
    public function __construct(public readonly array $result) {}
}
