<?php

declare(strict_types=1);

namespace Nvl\Content\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Services\ContentOwnerDeletion;

/**
 * Connects manually instantiated Eloquent owners to the provider-owned cleanup runtime.
 */
final class ContentOwnerDeletionBridge
{
    private static ?ContentOwnerDeletion $runtime = null;

    /**
     * Attach the deletion runtime supplied by the current Content provider.
     */
    public static function use(ContentOwnerDeletion $runtime): void
    {
        self::$runtime = $runtime;
    }

    /**
     * Release any runtime retained from a previous application lifecycle.
     */
    public static function clear(): void
    {
        self::$runtime = null;
    }

    /**
     * Delete one owner through the runtime without resolving container dependencies.
     *
     * @param  Closure(): (bool|null)  $delete
     */
    public static function delete(Model&ContentOwner $owner, Closure $delete): ?bool
    {
        $runtime = self::$runtime
            ?? throw new LogicException('Content owner deletion requires the booted Content provider.');

        return $runtime->execute($owner, $delete);
    }
}
