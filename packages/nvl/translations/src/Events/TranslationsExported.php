<?php

declare(strict_types=1);

namespace Nvl\Translations\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Reports a completed database-to-file translation synchronization.
 */
final class TranslationsExported implements ShouldDispatchAfterCommit
{
    /**
     * @param  array{scopes:int,locales:int,files:int,deleted:int,target:string}  $result
     */
    public function __construct(public readonly array $result) {}
}
