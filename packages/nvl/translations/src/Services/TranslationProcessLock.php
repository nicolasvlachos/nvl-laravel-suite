<?php

declare(strict_types=1);

namespace Nvl\Translations\Services;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Nvl\Translations\Exceptions\TranslationsException;
use Nvl\Translations\Exceptions\TranslationWorkspaceLockedException;
use Nvl\Translations\Support\TranslationConfiguration;

/**
 * Serializes workspace import, export, prune, and scan operations.
 */
final class TranslationProcessLock
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function execute(string $operation, Closure $callback): mixed
    {
        $configuredStore = config('translations.lock.store');
        $store = is_string($configuredStore) && trim($configuredStore) !== ''
            ? trim($configuredStore)
            : null;
        $cacheStore = Cache::store($store)->getStore();

        if (! $cacheStore instanceof LockProvider) {
            $storeClass = $cacheStore::class;

            throw new TranslationsException(
                "The configured translation lock store [{$storeClass}] does not support atomic locks.",
            );
        }

        $lock = $cacheStore->lock(
            'nvl:translations:workspace',
            TranslationConfiguration::positiveInteger('translations.lock.seconds', 300),
        );
        $wait = TranslationConfiguration::nonNegativeInteger('translations.lock.wait_seconds', 0);

        try {
            if ($wait > 0) {
                /** @var TResult $result */
                $result = $lock->block($wait, $callback);

                return $result;
            }

            if (! $lock->get()) {
                throw TranslationWorkspaceLockedException::forOperation($operation);
            }

            try {
                return $callback();
            } finally {
                $lock->release();
            }
        } catch (LockTimeoutException) {
            throw TranslationWorkspaceLockedException::forOperation($operation);
        }
    }
}
