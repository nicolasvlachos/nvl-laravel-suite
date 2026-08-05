<?php

declare(strict_types=1);

namespace Nvl\Csv\Providers;

use Illuminate\Support\ServiceProvider;
use Nvl\Data\Services\TypeScriptSourceRegistry;

/**
 * Registers generated TypeScript discovery and publishable agent guidance.
 */
final class CsvServiceProvider extends ServiceProvider
{
    /**
     * Register package TypeScript sources and publishing.
     */
    public function boot(TypeScriptSourceRegistry $typeScriptSources): void
    {
        $typeScriptSources->register(__DIR__.'/..', 'nvl/csv');
        $this->publishes([
            __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
        ], 'csv-skills');
    }
}
