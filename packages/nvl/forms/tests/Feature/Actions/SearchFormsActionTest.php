<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Nvl\Forms\Actions\Form\SearchFormsAction;
use Nvl\Forms\Models\Form;

test('search forms action filters by submissions and limit', function (): void {
    Form::factory()->create(['name' => 'Active Form', 'submissions_count' => 5]);
    Form::factory()->create(['name' => 'Inactive Form', 'submissions_count' => 0]);

    $result = app(SearchFormsAction::class)->execute([
        'has_submissions' => true,
        'limit' => 1,
    ]);

    expect($result->forms)->toHaveCount(1)
        ->and($result->forms->first()->displayName())->toBe('Active Form')
        ->and($result->total)->toBe(1);
});

test('search forms action can eager load relations safely', function (): void {
    $form = Form::factory()->create();
    $form->entries()->create([
        'subject' => 'Hello',
        'submitted_from' => 'example.com',
    ]);

    $result = app(SearchFormsAction::class)->execute([
        'with' => ['entries'],
        'limit' => 10,
    ]);

    expect($result->forms->first()->relationLoaded('entries'))->toBeTrue()
        ->and($result->total)->toBe(1);
});

test('search projections keep a constant query count as forms grow', function (): void {
    $create = static function (int $index): void {
        $form = Form::factory()->create(['name' => "Query Form {$index}"]);
        $form->entries()->create([
            'subject' => "Entry {$index}",
            'submitted_from' => 'example.com',
        ]);
    };
    $measure = static function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = app(SearchFormsAction::class)->execute([
            'with' => ['entries'],
            'limit' => 30,
        ]);
        $queryCount = count(DB::getQueryLog());

        foreach ($result->forms as $form) {
            $form->entries->count();
        }

        expect(DB::getQueryLog())->toHaveCount($queryCount);
        DB::disableQueryLog();

        return $queryCount;
    };

    $create(1);
    $singleQueryCount = $measure();

    foreach (range(2, 25) as $index) {
        $create($index);
    }

    $populatedQueryCount = $measure();

    expect($singleQueryCount)->toBeLessThanOrEqual(4)
        ->and($populatedQueryCount)->toBe($singleQueryCount);
});
