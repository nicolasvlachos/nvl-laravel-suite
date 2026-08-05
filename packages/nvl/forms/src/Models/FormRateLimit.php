<?php

declare(strict_types=1);

namespace Nvl\Forms\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nvl\Forms\Definitions\Tables\FormsTables;
use Nvl\Forms\Traits\FormRateLimitFilters;

/**
 * FormRateLimit model representing rate limiting data for forms.
 *
 * Audit events are intentionally omitted because counters update on every request.
 *
 * @property string $id
 * @property string $form_id
 * @property string $ip_address
 * @property int $submission_count
 * @property Carbon $window_start
 * @property Carbon $last_submission_at
 * @property bool $is_blocked
 * @property Carbon|null $blocked_until
 * @property int $violation_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Form $form
 */
class FormRateLimit extends Model
{
    use FormRateLimitFilters;
    use HasUuids;

    protected $table = FormsTables::FORM_RATE_LIMITS;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'form_id',
        'ip_address',
        'submission_count',
        'window_start',
        'last_submission_at',
        'is_blocked',
        'blocked_until',
        'violation_count',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submission_count' => 'integer',
            'window_start' => 'datetime',
            'last_submission_at' => 'datetime',
            'is_blocked' => 'boolean',
            'blocked_until' => 'datetime',
            'violation_count' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
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
     * Scope to include only blocked IPs.
     *
     * @param  Builder<FormRateLimit>  $query  Query builder instance
     */
    public function scopeBlocked(Builder $query): void
    {
        $query->where('is_blocked', true)
            ->where(function (Builder $q): void {
                /** @var Builder<FormRateLimit> $q */
                $q->whereNull('blocked_until')
                    ->orWhere('blocked_until', '>', now());
            });
    }

    /**
     * Scope to include only active rate limits (within current window).
     *
     * @param  Builder<FormRateLimit>  $query  Query builder instance
     */
    public function scopeActiveWindow(Builder $query): void
    {
        $query->where('window_start', '>', now()->subHour());
    }
}
