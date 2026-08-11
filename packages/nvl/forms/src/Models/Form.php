<?php

declare(strict_types=1);

namespace Nvl\Forms\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Nvl\Forms\Data\FormOptions;
use Nvl\Forms\Database\Factories\FormFactory;
use Nvl\Forms\Definitions\Tables\FormsTables;
use Nvl\Forms\Enums\FormStatus;
use Nvl\Forms\Enums\FormType;
use Nvl\Forms\Enums\Resolvement;
use Nvl\Forms\Traits\FormFilters;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\Enums\TranslationMutationPolicy;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\Translatable;

/**
 * Form model representing user-created forms with dynamic fields.
 *
 * @property string $id
 * @property string $handle
 * @property int $revision
 * @property FormStatus $status
 * @property Resolvement $resolvement
 * @property FormType $type
 * @property bool $restrict_public_access
 * @property bool $allow_multiple_registrations
 * @property bool $date_restricted
 * @property Carbon|null $available_from
 * @property Carbon|null $available_until
 * @property int $submissions_count
 * @property int $views_count
 * @property int $spam_count
 * @property Carbon|null $last_used_at
 * @property Carbon|null $first_used_at
 * @property bool $enable_honeypot
 * @property bool $enable_rate_limiting
 * @property int $rate_limit_per_hour
 * @property bool $require_csrf
 * @property array<string, mixed>|null $cors_settings
 * @property FormOptions|null $options
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, FormTranslation> $translations
 * @property-read Collection<int, FormEntry> $entries
 * @property-read Collection<int, AllowedOrigin> $allowedOrigins
 * @property-read Collection<int, FormAnalytic> $analytics
 * @property-read Collection<int, FormRateLimit> $rateLimits
 */
class Form extends Model implements TranslatableModel
{
    use FormFilters;

    /** @use HasFactory<FormFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;
    use Translatable;

    /**
     * @var string The database table name
     */
    protected $table = FormsTables::Forms;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'handle',
        'revision',
        'status',
        'resolvement',
        'type',
        'restrict_public_access',
        'allow_multiple_registrations',
        'date_restricted',
        'available_from',
        'available_until',
        'submissions_count',
        'views_count',
        'spam_count',
        'last_used_at',
        'first_used_at',
        'enable_honeypot',
        'enable_rate_limiting',
        'rate_limit_per_hour',
        'require_csrf',
        'cors_settings',
        'options',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'status' => FormStatus::class,
            'resolvement' => Resolvement::class,
            'type' => FormType::class,
            'restrict_public_access' => 'boolean',
            'allow_multiple_registrations' => 'boolean',
            'date_restricted' => 'boolean',
            'submissions_count' => 'integer',
            'views_count' => 'integer',
            'spam_count' => 'integer',
            'enable_honeypot' => 'boolean',
            'enable_rate_limiting' => 'boolean',
            'rate_limit_per_hour' => 'integer',
            'require_csrf' => 'boolean',
            'cors_settings' => 'array',
            'options' => FormOptions::class,
            'last_used_at' => 'datetime',
            'first_used_at' => 'datetime',
            'available_from' => 'datetime',
            'available_until' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): FormFactory
    {
        return FormFactory::new();
    }

    /**
     * Bootstrap model defaults prior to persistence.
     */
    protected static function booted(): void
    {
        static::creating(function (Form $form) {
            if (! isset($form->revision)) {
                $form->revision = 1;
            }
        });
    }

    /**
     * Define locale-specific public form copy and arbitrary content.
     */
    protected function defineTranslations(): RelatedTranslationDefinition
    {
        return new RelatedTranslationDefinition(
            translationModel: FormTranslation::class,
            foreignKey: 'form_id',
            fields: [
                'name',
                'description',
                'submit_button_label',
                'success_title',
                'success_message',
                'content',
            ],
            mutationPolicy: TranslationMutationPolicy::DomainActionOnly,
        );
    }

    /** Return the localized public name. */
    public function displayName(?string $locale = null): string
    {
        $translated = $this->translated('name', $locale);

        return is_string($translated) ? $translated : '';
    }

    /** Return the localized public description. */
    public function displayDescription(?string $locale = null): ?string
    {
        $translated = $this->translated('description', $locale);

        if (is_string($translated)) {
            return $translated;
        }

        return null;
    }

    /**
     * Return arbitrary localized public content.
     *
     * @return array<string, mixed>
     */
    public function localizedContent(?string $locale = null): array
    {
        $content = $this->translated('content', $locale);

        return $this->stringKeyedMap($content);
    }

    /**
     * Return every locale as the administrative mutation payload shape.
     *
     * @return array<string, array<string, mixed>>
     */
    public function translationPayloads(): array
    {
        $payloads = [];

        foreach ($this->getAllTranslations() as $translation) {
            $locale = $translation->getAttribute('locale');

            if (! is_string($locale)) {
                continue;
            }

            $payload = $this->stringKeyedMap($translation->getAttribute('content'));
            $payloads[$locale] = array_replace(
                $payloads[$locale] ?? [],
                $payload,
                array_filter([
                    'name' => $translation->getAttribute('name'),
                    'description' => $translation->getAttribute('description'),
                    'submitButtonLabel' => $translation->getAttribute('submit_button_label'),
                    'successTitle' => $translation->getAttribute('success_title'),
                    'successMessage' => $translation->getAttribute('success_message'),
                ], static fn (mixed $value): bool => $value !== null),
            );
        }

        return $payloads;
    }

    /**
     * Normalize arbitrary localized content into its documented object shape.
     *
     * @return array<string, mixed>
     */
    private function stringKeyedMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $normalized[$key] = $item;
            }
        }

        return $normalized;
    }

    /**
     * Determine if the form is available to be rendered/submitted at this moment.
     *
     * @return bool Whether the form is currently available
     */
    public function isAvailableNow(): bool
    {
        if (! $this->date_restricted) {
            return true;
        }

        $now = now();
        $fromOk = $this->available_from === null || $this->available_from->lte($now);
        $untilOk = $this->available_until === null || $this->available_until->gte($now);

        return $fromOk && $untilOk;
    }

    /**
     * Determine if the form can be rendered/submitted publicly at this moment.
     *
     * @return bool Whether the form is publicly available
     */
    public function isPubliclyAvailableNow(): bool
    {
        if (($this->status ?? null) !== FormStatus::ACTIVE) {
            return false;
        }

        return $this->isAvailableNow();
    }

    /**
     * Whether multiple registrations from the same client are allowed.
     * Defaults to true when column not present/populated.
     *
     * @return bool Whether multiple registrations are allowed
     */
    public function allowsMultipleRegistrations(): bool
    {
        $val = $this->getAttribute('allow_multiple_registrations');

        return $val === null || $val;
    }

    /**
     * Get the form entries relationship.
     *
     * @return HasMany<FormEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(FormEntry::class);
    }

    /**
     * Get the allowed origins relationship.
     *
     * @return HasMany<AllowedOrigin, $this>
     */
    public function allowedOrigins(): HasMany
    {
        return $this->hasMany(AllowedOrigin::class);
    }

    /**
     * Get the analytics relationship.
     *
     * @return HasMany<FormAnalytic, $this>
     */
    public function analytics(): HasMany
    {
        return $this->hasMany(FormAnalytic::class);
    }

    /**
     * Get the rate limits relationship.
     *
     * @return HasMany<FormRateLimit, $this>
     */
    public function rateLimits(): HasMany
    {
        return $this->hasMany(FormRateLimit::class);
    }

    /**
     * Scope to include forms with recent activity.
     *
     * @param  Builder<Form>  $query  Query builder instance
     */
    public function scopeRecentlyUsed(Builder $query): void
    {
        $query->whereNotNull('last_used_at')
            ->orderBy('last_used_at', 'desc');
    }

    /**
     * Scope to include only forms with an active status.
     *
     * @param  Builder<Form>  $query  Query builder instance
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', FormStatus::ACTIVE);
    }

    /**
     * Scope to include only forms that have received submissions.
     *
     * @param  Builder<Form>  $query  Query builder instance
     */
    public function scopeWithSubmissions(Builder $query): void
    {
        $query->where('submissions_count', '>', 0);
    }
}
