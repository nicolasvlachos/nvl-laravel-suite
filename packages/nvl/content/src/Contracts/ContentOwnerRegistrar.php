<?php

declare(strict_types=1);

namespace Nvl\Content\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Registers stable morph aliases for Eloquent Content owners.
 */
interface ContentOwnerRegistrar
{
    /**
     * Register one stable owner alias and model.
     *
     * @param  class-string<Model&ContentOwner>  $model
     */
    public function register(string $alias, string $model): void;

    /**
     * Return the registered model for an alias, or null when it is available.
     *
     * @return class-string<Model&ContentOwner>|null
     */
    public function registered(string $alias): ?string;
}
