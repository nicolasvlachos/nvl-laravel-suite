<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\TenantContentSitesProofCommand;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Nvl\Content\Schema\ContentDefinitionSource;
use Nvl\Content\Services\ContentDefinitionRegistry;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Contracts\TenantSiteResolver;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantSiteContext;

/** Installs only explicit host adapters for the sealed tenant publication consumer. */
final class TenantContentSitesServiceProvider extends ServiceProvider
{
    public const string A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    public const string B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    public function register(): void
    {
        $this->app->singleton(TenantDirectory::class, static fn (): TenantDirectory => new class implements TenantDirectory
        {
            public function find(TenantId $id): TenantDescriptor
            {
                if (! in_array($id->value, [TenantContentSitesServiceProvider::A, TenantContentSitesServiceProvider::B], true)) {
                    throw new TenantNotFound('Unknown sealed-consumer tenant.');
                }

                return new TenantDescriptor($id, TenantStatus::Active);
            }
        });
        $this->app->singleton(PlatformAccess::class, static fn (): PlatformAccess => new class implements PlatformAccess
        {
            public function authorize(PlatformOperation $operation): void {}
        });
        $this->app->singleton(TenantSiteResolver::class, static fn (): TenantSiteResolver => new class implements TenantSiteResolver
        {
            public function resolve(Request $request): TenantSiteContext
            {
                return match ($request->getHost()) {
                    'a.consumer.test' => TenantContentSitesServiceProvider::site(TenantContentSitesServiceProvider::A),
                    'b.consumer.test' => TenantContentSitesServiceProvider::site(TenantContentSitesServiceProvider::B),
                    default => throw new TenantNotFound('Unknown sealed-consumer site.'),
                };
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([TenantContentSitesProofCommand::class]);
        }
    }

    public function boot(ContentDefinitionRegistry $definitions): void
    {
        $definitions->register(new ContentDefinitionSource(
            key: 'site.hero',
            name: 'Site hero',
            description: 'Tenant publication proof block.',
            category: 'site',
            version: 1,
            view: null,
            schema: ['fields' => [[
                'key' => 'title',
                'type' => 'text',
                'label' => 'Title',
                'localized' => true,
                'required' => true,
            ]]],
            allowedScopes: ['site'],
            allowedRegions: ['main'],
        ));
    }

    public static function site(string $tenant): TenantSiteContext
    {
        return new TenantSiteContext(
            new TenantId($tenant),
            'default',
            $tenant === self::A ? 'https://a.consumer.test' : 'https://b.consumer.test',
        );
    }
}
