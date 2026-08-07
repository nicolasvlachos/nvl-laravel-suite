<?php

declare(strict_types=1);

namespace Nvl\Workbench;

use Illuminate\Foundation\Application as LaravelApplication;

/**
 * Keeps the executable workbench namespace independent from package runtime autoloading.
 */
final class Application extends LaravelApplication
{
    public function getNamespace(): string
    {
        return __NAMESPACE__.'\\';
    }
}
