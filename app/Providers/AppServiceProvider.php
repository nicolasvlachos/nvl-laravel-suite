<?php

declare(strict_types=1);

namespace Nvl\Workbench\Providers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Workbench\Models\IntegrationTestModel;

/**
 * Configures the monorepo application as an executable all-package reference consumer.
 */
final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register representative consumer-owned package integrations before providers boot.
     */
    public function register(): void
    {
        Config::set('content.owners.reference_models', IntegrationTestModel::class);
        Config::set('media.allowed_associable_types', [IntegrationTestModel::class]);
        Config::set('metafields.owners.reference_models', [
            'model' => IntegrationTestModel::class,
            'label' => 'Reference models',
            'supported_types' => [
                MetafieldTypeEnum::String->value,
                MetafieldTypeEnum::Integer->value,
            ],
            'sections' => ['general'],
            'runtime_status' => 'live',
        ]);
        Config::set('seo.owners.reference_models', IntegrationTestModel::class);
        Config::set('taxonomy.owners.reference_models', IntegrationTestModel::class);
        Config::set('taxonomy.taxonomies.category.allowed_owners', ['reference_models']);
        Config::set('taxonomy.taxonomies.tag.allowed_owners', ['reference_models']);
    }

    /**
     * Bootstrap application-owned services.
     */
    public function boot(): void {}
}
