<?php

declare(strict_types=1);

namespace Nvl\Forms\Builders;

use Illuminate\Database\Eloquent\Builder;
use Nvl\Forms\Enums\FormAnalyticEventType;
use Nvl\Forms\Models\FormAnalytic;

/**
 * Custom Eloquent builder for FormAnalytic query composition.
 *
 * Provides typed, chainable scopes for filtering analytics events by
 * event type and time period.
 *
 * @template TModel of FormAnalytic
 *
 * @extends Builder<TModel>
 */
class FormAnalyticBuilder extends Builder
{
    /**
     * Filter to view events only.
     */
    public function views(): static
    {
        $this->where('event_type', FormAnalyticEventType::VIEW);

        return $this;
    }

    /**
     * Filter to submission events only.
     */
    public function submissions(): static
    {
        $this->where('event_type', FormAnalyticEventType::SUBMISSION);

        return $this;
    }

    /**
     * Filter to spam-blocked events only.
     */
    public function spamBlocked(): static
    {
        $this->where('event_type', FormAnalyticEventType::SPAM_BLOCKED);

        return $this;
    }

    /**
     * Filter to events created today.
     */
    public function today(): static
    {
        $this->whereDate('created_at', now()->toDateString());

        return $this;
    }

    /**
     * Filter to events created this week (Monday to Sunday).
     */
    public function thisWeek(): static
    {
        $this->whereBetween('created_at', [
            now()->startOfWeek(),
            now()->endOfWeek(),
        ]);

        return $this;
    }

    /**
     * Filter to events created this month.
     */
    public function thisMonth(): static
    {
        $this->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year);

        return $this;
    }
}
