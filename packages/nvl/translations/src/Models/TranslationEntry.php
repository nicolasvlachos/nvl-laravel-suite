<?php

declare(strict_types=1);

namespace Nvl\Translations\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Nvl\Translations\Definitions\Tables\TranslationsTables;
use Nvl\Translations\Enums\TranslationSyncStatus;
use Nvl\Translations\Support\TranslationIdentity;
use Nvl\Translations\Traits\TranslationEntryFilters;

/**
 * Single translation value row scoped by location and locale.
 *
 * @property string $id
 * @property string $identity_hash
 * @property string $scope_type
 * @property string $scope_name
 * @property string $locale
 * @property string $format
 * @property string $group
 * @property string $key
 * @property string|null $value
 * @property string|null $source_hash
 * @property bool $is_missing
 * @property int $revision
 * @property TranslationSyncStatus $sync_status
 * @property array<string, mixed>|null $conflict_metadata
 * @property CarbonImmutable|null $last_imported_at
 * @property CarbonImmutable|null $last_exported_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class TranslationEntry extends Model
{
    use HasUuids;
    use TranslationEntryFilters;

    protected $table = TranslationsTables::Entries;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'revision' => 1,
        'sync_status' => 'synchronized',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'identity_hash',
        'scope_type',
        'scope_name',
        'locale',
        'format',
        'group',
        'key',
        'value',
        'source_hash',
        'is_missing',
        'last_imported_at',
        'last_exported_at',
        'revision',
        'sync_status',
        'conflict_metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_missing' => 'boolean',
            'last_imported_at' => 'immutable_datetime',
            'last_exported_at' => 'immutable_datetime',
            'revision' => 'integer',
            'sync_status' => TranslationSyncStatus::class,
            'conflict_metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (TranslationEntry $entry): void {
            $identityHash = $entry->getAttribute('identity_hash');

            if ($entry->isDirty(['scope_type', 'scope_name', 'locale', 'format', 'group', 'key'])
                || ! is_string($identityHash)
                || $identityHash === '') {
                $entry->identity_hash = TranslationIdentity::entry(
                    $entry->scope_type,
                    $entry->scope_name,
                    $entry->locale,
                    $entry->format,
                    $entry->group,
                    $entry->key,
                );
            }
        });

        self::updating(function (TranslationEntry $entry): void {
            if (! $entry->isDirty('revision')) {
                $original = $entry->getOriginal('revision');
                $entry->revision = is_numeric($original) ? ((int) $original) + 1 : 1;
            }
        });
    }

    /**
     * Canonical key as used by scanner matching.
     */
    public function fullKey(): string
    {
        if ($this->format === 'php' && $this->group !== '*') {
            return $this->group.'.'.$this->key;
        }

        return $this->key;
    }
}
