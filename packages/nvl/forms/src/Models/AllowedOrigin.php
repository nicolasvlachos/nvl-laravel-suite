<?php

declare(strict_types=1);

namespace Nvl\Forms\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nvl\Forms\Database\Factories\AllowedOriginFactory;
use Nvl\Forms\Definitions\Tables\FormsTables;
use Nvl\Forms\Traits\AllowedOriginFilters;

/**
 * AllowedOrigin model representing CORS-allowed origins for forms.
 *
 * @property string $id
 * @property string $form_id
 * @property string $origin
 * @property bool $is_active
 * @property string|null $description
 * @property array<string, mixed>|null $cors_settings
 * @property int $usage_count
 * @property Carbon|null $last_used_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Form $form
 */
class AllowedOrigin extends Model
{
    use AllowedOriginFilters;

    /** @use HasFactory<AllowedOriginFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = FormsTables::ALLOWED_ORIGINS;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'form_id',
        'origin',
        'is_active',
        'description',
        'cors_settings',
        'usage_count',
        'last_used_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'cors_settings' => 'array',
            'usage_count' => 'integer',
            'last_used_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): AllowedOriginFactory
    {
        return AllowedOriginFactory::new();
    }

    /**
     * Get the form relationship.
     *
     * @return BelongsTo<Form, $this>
     */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /**
     * Scope to include only active origins.
     *
     * @param  Builder<AllowedOrigin>  $query  Query builder instance
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope to include recently used origins.
     *
     * @param  Builder<AllowedOrigin>  $query  Query builder instance
     */
    public function scopeRecentlyUsed(Builder $query): void
    {
        $query->whereNotNull('last_used_at')
            ->orderBy('last_used_at', 'desc');
    }
}
