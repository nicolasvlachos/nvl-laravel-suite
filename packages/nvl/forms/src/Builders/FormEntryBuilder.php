<?php

declare(strict_types=1);

namespace Nvl\Forms\Builders;

use Illuminate\Database\Eloquent\Builder;
use Nvl\Forms\Models\FormEntry;

/**
 * Custom Eloquent builder for FormEntry query composition.
 *
 * Provides typed, chainable scopes for filtering form entries by
 * email presence, domain, recency, spam status, and IP address.
 *
 * @template TModel of FormEntry
 *
 * @extends Builder<TModel>
 */
class FormEntryBuilder extends Builder
{
    /**
     * Filter to entries that have a non-null email address.
     */
    public function withEmail(): static
    {
        $this->whereNotNull('email');

        return $this;
    }

    /**
     * Filter to entries submitted from a specific domain.
     *
     * @param  string  $domain  The domain to match against submitted_from
     */
    public function fromDomain(string $domain): static
    {
        $this->where('submitted_from', $domain);

        return $this;
    }

    /**
     * Filter to entries created within the last N days.
     *
     * @param  int  $days  Number of days to look back (default: 30)
     */
    public function recent(int $days = 30): static
    {
        $this->where('created_at', '>=', now()->subDays($days));

        return $this;
    }

    /**
     * Filter to entries marked as legitimate (not spam).
     */
    public function legitimate(): static
    {
        $this->where('is_spam', false);

        return $this;
    }

    /**
     * Filter to entries marked as spam.
     */
    public function spam(): static
    {
        $this->where('is_spam', true);

        return $this;
    }

    /**
     * Filter to entries submitted from a specific IP address.
     *
     * @param  string  $ipAddress  IP address to match
     */
    public function fromIp(string $ipAddress): static
    {
        $this->where('ip_address', $ipAddress);

        return $this;
    }
}
