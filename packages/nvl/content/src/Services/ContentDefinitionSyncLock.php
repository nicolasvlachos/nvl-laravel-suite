<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use InvalidArgumentException;
use Nvl\Content\Support\ContentConfiguration;

/**
 * Serializes definition mirror synchronization across deployment processes.
 */
final readonly class ContentDefinitionSyncLock
{
    public function __construct(private Repository $cache) {}

    /**
     * Execute synchronization while holding the package-wide atomic lock.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function run(Closure $callback): mixed
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new InvalidArgumentException(
                'The configured cache store must support atomic locks for Content definition synchronization.',
            );
        }

        $seconds = ContentConfiguration::positiveInteger(
            'content.definition_sync.lock_seconds',
            60,
        );
        $wait = ContentConfiguration::positiveInteger(
            'content.definition_sync.lock_wait_seconds',
            10,
        );

        return $store->lock('nvl:content:definitions:sync', $seconds)
            ->block($wait, $callback);
    }
}
