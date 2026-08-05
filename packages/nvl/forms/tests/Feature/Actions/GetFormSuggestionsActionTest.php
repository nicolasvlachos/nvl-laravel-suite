<?php

declare(strict_types=1);

use Nvl\Forms\Actions\Form\GetFormSuggestionsAction;
use Nvl\Forms\Models\Form;

test('get form suggestions action ranks and limits results', function (): void {
    $first = Form::factory()->create(['name' => 'Demo Signup', 'handle' => 'demo-signup', 'submissions_count' => 5]);
    $second = Form::factory()->create(['name' => 'Product Demo', 'handle' => 'product-demo', 'submissions_count' => 10]);
    Form::factory()->create(['name' => 'Support Request', 'handle' => 'support-request']);

    $results = app(GetFormSuggestionsAction::class)->execute('demo', 2);

    expect($results)->toHaveCount(2)
        ->and($results->first())->toBeInstanceOf(Form::class)
        ->and($results->first()->id)->toBe($second->id)
        ->and($results->first()->displayName())->toBe('Product Demo');
});
