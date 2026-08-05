<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Nvl\Forms\Data\FormSearchFilter;

test('form search data validation accepts filter combinations', function (): void {
    $payload = [
        'search' => 'demo',
        'hasSubmissions' => true,
        'limit' => 10,
        'with' => ['entries'],
        'status' => 'active',
    ];

    $validator = Validator::make($payload, FormSearchFilter::rules(), FormSearchFilter::messages(), FormSearchFilter::attributes());

    expect($validator->passes())->toBeTrue();
});

test('form search data validation rejects invalid relations', function (): void {
    $payload = ['with' => ['invalid']];

    $validator = Validator::make($payload, FormSearchFilter::rules());

    expect($validator->fails())->toBeTrue();
});

test('form search data validation enforces limit bounds', function (): void {
    $payload = ['limit' => 101];

    $validator = Validator::make($payload, FormSearchFilter::rules());

    expect($validator->fails())->toBeTrue();
});

test('form search data validation rejects invalid status', function (): void {
    $payload = ['status' => 'invalid'];

    $validator = Validator::make($payload, FormSearchFilter::rules());

    expect($validator->fails())->toBeTrue();
});
