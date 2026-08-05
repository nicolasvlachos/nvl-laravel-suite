<?php

declare(strict_types=1);

namespace Nvl\Forms\Actions\Form;

use Illuminate\Support\Collection;
use Nvl\Forms\Enums\FormAnalyticEventType;
use Nvl\Forms\Models\Form;

/**
 * Provides aggregated analytics metrics for a form.
 */
final class GetFormAnalyticsSummaryAction
{
    /**
     * Build an analytics summary for the provided form.
     *
     * @param  Form|string  $form  Form instance or identifier
     * @param  int  $days  Rolling window in days to include in counts
     * @return array{total_views:int,total_submissions:int,spam_blocked:int,conversion_rate:float,top_origins:array<string,int>}
     */
    public function execute(Form|string $form, int $days = 30): array
    {
        $formModel = $form instanceof Form ? $form : Form::findOrFail($form);
        $startDate = now()->subDays($days);

        $totalViews = $formModel->analytics()
            ->where('event_type', FormAnalyticEventType::VIEW)
            ->where('created_at', '>=', $startDate)
            ->count();

        $totalSubmissions = $formModel->analytics()
            ->where('event_type', FormAnalyticEventType::SUBMISSION)
            ->where('created_at', '>=', $startDate)
            ->count();

        $spamBlocked = $formModel->analytics()
            ->where('event_type', FormAnalyticEventType::SPAM_BLOCKED)
            ->where('created_at', '>=', $startDate)
            ->count();

        $conversionRate = $totalViews > 0
            ? round(($totalSubmissions / $totalViews) * 100, 2)
            : 0.0;

        $topOrigins = $this->buildTopOrigins($formModel, $days, 10);

        return [
            'total_views' => $totalViews,
            'total_submissions' => $totalSubmissions,
            'spam_blocked' => $spamBlocked,
            'conversion_rate' => $conversionRate,
            'top_origins' => $topOrigins,
        ];
    }

    /**
     * @return array<string,int>
     */
    private function buildTopOrigins(Form $form, int $days, int $limit): array
    {
        $startDate = now()->subDays($days);

        /** @var Collection<int,string> $origins */
        $origins = $form->analytics()
            ->where('created_at', '>=', $startDate)
            ->whereNotNull('origin')
            ->pluck('origin');

        $counts = [];
        foreach ($origins as $origin) {
            $counts[(string) $origin] = ($counts[(string) $origin] ?? 0) + 1;
        }

        arsort($counts);

        return array_slice($counts, 0, $limit, true);
    }
}
