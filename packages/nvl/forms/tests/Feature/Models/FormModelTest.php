<?php

declare(strict_types=1);

use Nvl\Forms\Enums\FormStatus;
use Nvl\Forms\Models\AllowedOrigin;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Services\FormOriginAccessService;

test('form model determines availability based on date restrictions', function (): void {
    $form = Form::factory()->create([
        'date_restricted' => true,
        'available_from' => now()->subDay(),
        'available_until' => now()->addDay(),
    ]);

    expect($form->isAvailableNow())->toBeTrue();

    $form->update(['available_from' => now()->addDay()]);

    expect($form->fresh()->isAvailableNow())->toBeFalse();
});

test('form origin access service checks allowed origins', function (): void {
    $form = Form::factory()->create(['restrict_public_access' => true]);
    AllowedOrigin::factory()->for($form)->create(['origin' => '*.example.com']);
    $originAccess = app(FormOriginAccessService::class);

    expect($originAccess->isOriginAllowed($form, 'https://shop.example.com'))->toBeTrue()
        ->and($originAccess->isOriginAllowed($form, 'https://other.com'))->toBeFalse();
});

test('form model initializes its revision on create', function (): void {
    $form = Form::factory()->create();

    expect($form->revision)->toBe(1);
});

test('form active scope uses status and with submissions scope preserves historical usage semantics', function (): void {
    $activeWithoutSubmissions = Form::factory()->create([
        'status' => FormStatus::ACTIVE,
        'submissions_count' => 0,
    ]);
    $pausedWithSubmissions = Form::factory()->create([
        'status' => FormStatus::PAUSED,
        'submissions_count' => 3,
    ]);

    expect(Form::query()->active()->pluck('id')->all())->toContain($activeWithoutSubmissions->id)
        ->not->toContain($pausedWithSubmissions->id)
        ->and(Form::query()->withSubmissions()->pluck('id')->all())->toContain($pausedWithSubmissions->id);
});
