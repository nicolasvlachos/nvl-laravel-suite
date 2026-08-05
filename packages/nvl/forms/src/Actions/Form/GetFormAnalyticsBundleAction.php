<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\Form;

use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;

/**
 * Orchestrates canonical form retrieval and analytics aggregation.
 *
 * The approved action composition keeps display loading and metric calculation
 * consistent for every analytics surface.
 */
final class GetFormAnalyticsBundleAction
{
    /**
     * @param  ShowFormAction  $showForm  Action that resolves the display form
     * @param  GetFormAnalyticsSummaryAction  $getFormAnalyticsSummary  Action that aggregates analytics metrics
     */
    public function __construct(
        private readonly ShowFormAction $showForm,
        private readonly GetFormAnalyticsSummaryAction $getFormAnalyticsSummary,
    ) {}

    /**
     * Fetch a form alongside analytics data and a small sample of recent entries.
     *
     * @param  Form|string  $form  Form instance or identifier
     * @param  int  $analyticsDays  Rolling window for analytics aggregation
     * @return array{form: Form, analytics: array<string, mixed>, recent_entries: array<int, array<string, mixed>>}
     */
    public function execute(Form|string $form, int $analyticsDays = 30): array
    {
        $formModel = $this->showForm->execute($form, false);
        $summary = $this->getFormAnalyticsSummary->execute($formModel, $analyticsDays);

        /** @var array<int, array<string, mixed>> $recent */
        $recent = $formModel->entries
            ->take(5)
            ->map(static fn (FormEntry $entry): array => $entry->toArray())
            ->values()
            ->all();

        return [
            'form' => $formModel,
            'analytics' => $summary,
            'recent_entries' => $recent,
        ];
    }
}
