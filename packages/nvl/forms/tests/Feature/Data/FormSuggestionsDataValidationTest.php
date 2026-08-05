<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Nvl\Forms\Data\FormSuggestions;

test('form suggestions data validation requires search term', function (): void {
    $payload = ['q' => 'signup', 'limit' => 5];

    $validator = Validator::make($payload, FormSuggestions::rules(), FormSuggestions::messages(), FormSuggestions::attributes());

    expect($validator->passes())->toBeTrue();
});

test('form suggestions data validation enforces minimum length', function (): void {
    $payload = ['q' => 'a'];

    $validator = Validator::make($payload, FormSuggestions::rules());

    expect($validator->fails())->toBeTrue();
});
