<?php

declare(strict_types=1);

namespace Nvl\Translations\Actions;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Translations\Data\TenantTranslationExportData;
use Nvl\Translations\Models\TenantTranslationOverride;
use RuntimeException;

/** Writes the current tenant's bounded overrides to a private isolated artifact. */
final readonly class ExportTenantTranslationsAction
{
    public function __construct(private TenantContext $context, private TenantBoundary $boundary) {}

    public function execute(string $disk): TenantTranslationExportData
    {
        $tenantId = $this->context->requireTenant()->value;
        $rows = $this->boundary->query(TenantTranslationOverride::query(), 'translations.overrides')
            ->orderBy('key')->orderBy('locale')->get(['key', 'locale', 'value', 'revision']);
        $payload = json_encode([
            'tenant_id' => $tenantId,
            'translations' => $rows->map(fn (TenantTranslationOverride $row): array => [
                'key' => $row->key,
                'locale' => $row->locale,
                'value' => $row->value,
                'revision' => $row->revision,
            ])->all(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $path = 'tenants/'.$tenantId.'/translations/'.Str::uuid().'.json';
        if (! Storage::disk($disk)->put($path, $payload, ['visibility' => 'private'])) {
            throw new RuntimeException('Unable to write tenant translation artifact.');
        }

        return new TenantTranslationExportData($disk, $path, hash('sha256', $payload), strlen($payload));
    }
}
