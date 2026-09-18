<?php

declare(strict_types=1);

namespace Nvl\Seo\Models;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Nvl\Seo\Definitions\Tables\SeoTables;
use Nvl\Seo\Models\Concerns\GuardsTenantOwnership;
use Nvl\Seo\Support\SeoPath;
use Nvl\Seo\Support\SeoScope;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Exceptions\TenantContextMissing;
use Nvl\Translatable\Support\LocaleCode;

/**
 * One scoped, optionally localized HTTP redirect.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $scope
 * @property string|null $locale
 * @property string $source_path
 * @property string $source_hash
 * @property string $target
 * @property int $status_code
 * @property bool $is_active
 * @property Carbon|null $expires_at
 * @property int $hit_count
 * @property Carbon|null $last_hit_at
 * @property int $revision
 * @property array<string, mixed>|null $metadata
 */
final class SeoRedirect extends Model
{
    use GuardsTenantOwnership;
    use HasUuids;
    use SoftDeletes;

    public const string TENANT_RESOURCE = 'seo.redirects';

    protected $table = SeoTables::Redirects;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'scope' => 'default',
        'status_code' => 301,
        'is_active' => true,
        'hit_count' => 0,
        'revision' => 1,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'scope',
        'locale',
        'source_path',
        'target',
        'status_code',
        'is_active',
        'expires_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'is_active' => 'boolean',
            'expires_at' => 'immutable_datetime',
            'hit_count' => 'integer',
            'last_hit_at' => 'immutable_datetime',
            'revision' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (SeoRedirect $redirect): void {
            $redirect->scope = SeoScope::normalize($redirect->scope);
            $redirect->locale = $redirect->locale === null
                ? null
                : (new LocaleCode($redirect->locale))->value;
            $redirect->source_path = SeoPath::normalize($redirect->source_path) ?? '/';
            $redirect->source_hash = self::sourceHash(
                $redirect->scope,
                $redirect->locale,
                $redirect->source_path,
            );
        });
        self::updating(function (SeoRedirect $redirect): void {
            if (! $redirect->isDirty('revision')) {
                $original = $redirect->getOriginal('revision');
                $redirect->revision = is_numeric($original) ? ((int) $original) + 1 : 1;
            }
        });
    }

    public static function sourceHash(string $scope, ?string $locale, string $source): string
    {
        $tenant = '*';
        $container = Container::getInstance();
        if ($container->bound('config') && $container->make('config')->get('tenancy.enabled') === true) {
            $snapshot = $container->make(TenantContext::class)->snapshot();
            if ($snapshot->mode !== TenantContextMode::Tenant || $snapshot->tenantId === null) {
                throw new TenantContextMissing;
            }
            $tenant = $snapshot->tenantId->value;
        }

        return self::sourceHashForTenant($tenant, $scope, $locale, $source);
    }

    public static function sourceHashForTenant(string $tenantId, string $scope, ?string $locale, string $source): string
    {
        $locale = $locale === null ? '*' : (new LocaleCode($locale))->value;
        $scope = mb_strtolower(trim($scope));
        if ($scope === '' || mb_strlen($scope) > 100
            || preg_match('/^[a-z0-9][a-z0-9._-]*$/', $scope) !== 1) {
            throw new \InvalidArgumentException('An SEO scope must use its canonical stored form.');
        }

        return hash('sha256', implode('|', [
            $tenantId,
            $scope,
            $locale,
            SeoPath::normalize($source) ?? '/',
        ]));
    }
}
