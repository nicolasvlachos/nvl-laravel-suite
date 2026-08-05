<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Nvl\Forms\Actions\Form\GetFormValidationSchemaAction;
use Nvl\Forms\Data\Display\PublicFormSchemaPayload;
use Nvl\Forms\Models\Form;

test('get form validation schema action returns a JSON-safe schema', function (): void {
    $form = Form::factory()->create();

    $schema = app(GetFormValidationSchemaAction::class)->execute($form->id);

    expect($schema)->toBeInstanceOf(PublicFormSchemaPayload::class)
        ->and($schema->formId)->toBe($form->id)
        ->and($schema->fields)->not->toBeEmpty()
        ->and($schema->validationRules)->toBeArray()
        ->and($schema->validationRules['submissionData'])->toContain('custom:BoundedSubmissionPayload');
});

test('get form validation schema action throws when form not found', function (): void {
    $this->expectException(Exception::class);

    app(GetFormValidationSchemaAction::class)->execute((string) Str::uuid());
});

test('get form validation schema action resolves a handle without querying the UUID key', function (): void {
    $form = Form::factory()->create([
        'handle' => 'public-registration',
    ]);

    $schema = app(GetFormValidationSchemaAction::class)->execute('public-registration');

    expect($schema->formId)->toBe($form->id);
});
