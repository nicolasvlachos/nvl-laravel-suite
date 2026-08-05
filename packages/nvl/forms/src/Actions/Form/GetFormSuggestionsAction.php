<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\Form;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Nvl\Forms\Models\Form;

/**
 * Retrieves form suggestions for autocomplete functionality.
 * This action handles form search and suggestion logic with proper
 * ranking and ordering for optimal user experience.
 */
final class GetFormSuggestionsAction
{
    /**
     * Execute the form suggestions retrieval.
     *
     * @param  string  $query  Search query term
     * @param  int  $limit  Maximum number of suggestions to return
     * @return Collection<int, Form>
     */
    public function execute(string $query, int $limit = 10): Collection
    {
        $lower = mb_strtolower($query);

        return Form::query()
            ->withResolvedTranslations()
            ->select(['id', 'handle', 'submissions_count', 'last_used_at'])
            ->where(function (Builder $q) use ($lower) {
                $q->whereRaw('LOWER(handle) LIKE ?', ['%'.$lower.'%'])
                    ->orWhereHas('translations', function (Builder $translations) use ($lower): void {
                        $translations->whereRaw('LOWER(name) LIKE ?', ['%'.$lower.'%']);
                    });
            })
            ->orderBy('submissions_count', 'desc')
            ->orderBy('handle')
            ->limit($limit)
            ->get();
    }
}
