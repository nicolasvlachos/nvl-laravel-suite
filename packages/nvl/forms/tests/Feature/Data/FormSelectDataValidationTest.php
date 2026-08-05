<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Nvl\Forms\Data\FormSelectOption;

test('form select data validation allows optional boolean filters', function (): void {
    $payload = [
        'q' => 'signup',
        'activeOnly' => true,
        'publicOnly' => true,
        'withSubmissions' => false,
        'status' => 'active',
    ];

    $validator = Validator::make($payload, FormSelectOption::rules(), FormSelectOption::messages(), FormSelectOption::attributes());

    expect($validator->passes())->toBeTrue();
});

test('form select data validation rejects overly long search terms', function (): void {
    $payload = ['q' => str_repeat('a', 101)];

    $validator = Validator::make($payload, FormSelectOption::rules());

    expect($validator->fails())->toBeTrue();
});

test('form select data validation rejects invalid status', function (): void {
    $payload = ['status' => 'invalid'];

    $validator = Validator::make($payload, FormSelectOption::rules());

    expect($validator->fails())->toBeTrue();
});
