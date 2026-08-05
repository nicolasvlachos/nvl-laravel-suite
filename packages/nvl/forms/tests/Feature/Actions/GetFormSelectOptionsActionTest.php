<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Nvl\Forms\Actions\Form\GetFormSelectOptionsAction;
use Nvl\Forms\Models\Form;

test('get form select options action filters by public visibility and submissions', function (): void {
    $public = Form::factory()->create([
        'name' => 'Public Form',
        'restrict_public_access' => false,
        'submissions_count' => 3,
    ]);

    Form::factory()->create([
        'name' => 'Private Form',
        'restrict_public_access' => true,
        'submissions_count' => 10,
    ]);

    $options = app(GetFormSelectOptionsAction::class)->execute([
        'publicOnly' => true,
        'withSubmissions' => true,
    ]);

    expect($options)->toHaveCount(1)
        ->and($options->first()->id)->toBe($public->id)
        ->and($options->first()->sublabel)->toBe($public->handle);
});

test('get form select options action ignores ambient request filters', function (): void {
    $public = Form::factory()->create([
        'name' => 'Public Form',
        'restrict_public_access' => false,
        'submissions_count' => 3,
    ]);

    Form::factory()->create([
        'name' => 'Private Form',
        'restrict_public_access' => true,
        'submissions_count' => 10,
    ]);

    app()->instance('request', Request::create('/api/v1/forms/select', 'GET', [
        'q' => 'Private',
        'publicOnly' => false,
    ]));

    $options = app(GetFormSelectOptionsAction::class)->execute([
        'publicOnly' => true,
        'withSubmissions' => true,
    ]);

    expect($options)->toHaveCount(1)
        ->and($options->first()->id)->toBe($public->id);
});
