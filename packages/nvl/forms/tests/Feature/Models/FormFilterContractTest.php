<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Nvl\Filterable\Data\FilterCriterion;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Filterable\Enums\FilterOperator;
use Nvl\Filterable\Exceptions\FilterableException;
use Nvl\Forms\Enums\FormAnalyticEventType;
use Nvl\Forms\Models\AllowedOrigin;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormAnalytic;
use Nvl\Forms\Models\FormEntry;
use Nvl\Forms\Models\FormRateLimit;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-20 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('form filters support localized search and last-used presets', function (): void {
    $today = Form::factory()->create([
        'handle' => 'customer-contact',
        'name' => 'Customer Contact',
        'last_used_at' => now(),
    ]);
    $week = Form::factory()->create([
        'handle' => 'weekly-report',
        'last_used_at' => now()->subDays(2),
    ]);
    $month = Form::factory()->create([
        'handle' => 'monthly-report',
        'last_used_at' => now()->subWeeks(2),
    ]);
    $never = Form::factory()->create([
        'handle' => 'never-used',
        'last_used_at' => null,
    ]);
    $model = new Form;

    expect($model->filterSearch(Form::query(), 'CONTACT')->pluck('id')->all())->toBe([$today->id])
        ->and($model->filterSearch(Form::query(), ['invalid'])->count())->toBe(4)
        ->and($model->filterLastUsedAt(Form::query(), 'today')->pluck('id')->all())->toBe([$today->id])
        ->and($model->filterLastUsedAt(Form::query(), 'this_week')->pluck('id')->all())->toContain($today->id, $week->id)
        ->and($model->filterLastUsedAt(Form::query(), 'this_month')->pluck('id')->all())->toContain($today->id, $week->id, $month->id)
        ->and($model->filterLastUsedAt(Form::query(), 'never')->pluck('id')->all())->toBe([$never->id]);

    expect(fn () => $model->filterLastUsedAt(Form::query(), 10))
        ->toThrow(FilterableException::class, 'must be a string')
        ->and(fn () => $model->filterLastUsedAt(Form::query(), 'yesterday'))
        ->toThrow(FilterableException::class, 'Unknown last-used');
});

test('form availability filters cover every supported transport shape and operator', function (): void {
    $unrestricted = Form::factory()->create([
        'handle' => 'always',
        'date_restricted' => false,
    ]);
    $current = Form::factory()->create([
        'handle' => 'current',
        'date_restricted' => true,
        'available_from' => '2026-08-01 00:00:00',
        'available_until' => '2026-08-03 00:00:00',
    ]);
    $future = Form::factory()->create([
        'handle' => 'future',
        'date_restricted' => true,
        'available_from' => '2026-09-01 00:00:00',
        'available_until' => null,
    ]);
    $model = new Form;

    $between = $model->filterAvailability(
        Form::query(),
        ['2026-08-02 00:00:00', '2026-08-04 00:00:00'],
        FilterOperator::Between,
    )->pluck('id')->all();
    $before = $model->filterAvailability(
        Form::query(),
        '2026-08-04 00:00:00',
        FilterOperator::Before,
    )->pluck('id')->all();
    $after = $model->filterAvailability(
        Form::query(),
        CarbonImmutable::parse('2026-08-15 00:00:00'),
        FilterOperator::After,
    )->pluck('id')->all();
    $equals = Form::query()->applyFilterSet(new FilterSet([
        new FilterCriterion('availability', FilterOperator::Equals, '2026-08-02T12:00:00+00:00'),
    ]))->pluck('id')->all();

    expect($between)->toContain($unrestricted->id, $current->id)
        ->and($between)->not->toContain($future->id)
        ->and($before)->toContain($unrestricted->id, $current->id)
        ->and($after)->toContain($unrestricted->id, $future->id)
        ->and($equals)->toContain($unrestricted->id, $current->id)
        ->and($equals)->not->toContain($future->id);

    expect(fn () => $model->filterAvailability(Form::query(), new stdClass, FilterOperator::Equals))
        ->toThrow(FilterableException::class, 'must be a date')
        ->and(fn () => $model->filterAvailability(Form::query(), '2026-08-02', FilterOperator::Contains))
        ->toThrow(FilterableException::class, 'Unsupported availability operator');
});

test('allowed-origin entry analytic and rate-limit models publish working allowlists', function (): void {
    $form = Form::factory()->create(['name' => 'Customer Support']);
    $origin = AllowedOrigin::factory()->for($form)->create([
        'origin' => 'portal.example.test',
        'description' => 'Trusted customer portal',
    ]);
    $entry = FormEntry::factory()->for($form)->create([
        'subject' => 'Priority request',
        'email' => 'customer@example.test',
    ]);
    $analytic = FormAnalytic::query()->create([
        'form_id' => $form->id,
        'event_type' => FormAnalyticEventType::VIEW,
        'origin' => 'portal.example.test',
        'ip_address' => '192.0.2.10',
    ]);
    $rateLimit = FormRateLimit::query()->create([
        'form_id' => $form->id,
        'ip_address' => '192.0.2.10',
        'submission_count' => 2,
        'window_start' => now(),
        'last_submission_at' => now(),
        'is_blocked' => true,
        'blocked_until' => now()->addMinute(),
        'violation_count' => 1,
    ]);

    expect(AllowedOrigin::query()->applyFilterSet(new FilterSet([
        new FilterCriterion('search', FilterOperator::Equals, 'CUSTOMER'),
    ]))->pluck('id')->all())->toBe([$origin->id])
        ->and((new AllowedOrigin)->filterSearch(AllowedOrigin::query(), 10)->count())->toBe(1)
        ->and(FormEntry::query()->applyFilterSet(new FilterSet([
            new FilterCriterion('search', FilterOperator::Equals, 'priority'),
        ]))->pluck('id')->all())->toBe([$entry->id])
        ->and((new FormEntry)->filterSearch(FormEntry::query(), null)->count())->toBe(1)
        ->and(FormAnalytic::query()->applyFilterSet(new FilterSet([
            new FilterCriterion('event_type', FilterOperator::Equals, 'view'),
        ]))->pluck('id')->all())->toBe([$analytic->id])
        ->and(FormRateLimit::query()->applyFilterSet(new FilterSet([
            new FilterCriterion('is_blocked', FilterOperator::Equals, true),
        ]))->pluck('id')->all())->toBe([$rateLimit->id]);

    expect((new AllowedOrigin)->filterSchema()->filters)->toHaveCount(6)
        ->and((new FormAnalytic)->filterSchema()->filters)->toHaveCount(5)
        ->and((new FormRateLimit)->filterSchema()->filters)->toHaveCount(4)
        ->and((new FormEntry)->filterSchema()->filters)->toHaveCount(12);
});
