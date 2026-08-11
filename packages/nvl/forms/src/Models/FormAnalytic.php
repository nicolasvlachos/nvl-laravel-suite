<?php

declare(strict_types=1);

namespace Nvl\Forms\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;
use Nvl\Forms\Builders\FormAnalyticBuilder;
use Nvl\Forms\Definitions\Tables\FormsTables;
use Nvl\Forms\Enums\FormAnalyticEventType;
use Nvl\Forms\Traits\FormAnalyticFilters;

/**
 * FormAnalytic model representing form analytics events.
 *
 * Activity logging is intentionally omitted because analytics rows are
 * high-volume operational telemetry, not operator-facing history.
 *
 * @property string $id
 * @property string $form_id
 * @property FormAnalyticEventType $event_type
 * @property string|null $origin
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $session_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Form $form
 */
class FormAnalytic extends Model
{
    use FormAnalyticFilters;
    use HasUuids;

    protected $table = FormsTables::Analytics;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'form_id',
        'event_type',
        'origin',
        'ip_address',
        'user_agent',
        'session_id',
        'metadata',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => FormAnalyticEventType::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Create a new Eloquent builder instance for the model.
     *
     * @param  Builder  $query  Base query builder
     * @return FormAnalyticBuilder<FormAnalytic>
     */
    public function newEloquentBuilder($query): FormAnalyticBuilder
    {
        return new FormAnalyticBuilder($query);
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
}
