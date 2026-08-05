<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;
use Nvl\Forms\Services\EntryCallbackRegistry;

test('entry callback failures are reported without stopping later callbacks', function (): void {
    $form = Form::factory()->create(['handle' => 'callback-isolation']);
    $entry = FormEntry::factory()->for($form)->create();
    $laterCallbackRan = false;
    $registry = app(EntryCallbackRegistry::class);

    $registry->register('callback-isolation', [
        static function (): void {
            throw new RuntimeException('Downstream callback failed.');
        },
        static function () use (&$laterCallbackRan): void {
            $laterCallbackRan = true;
        },
    ]);

    $registry->dispatch($form, $entry, Request::create('/submit', 'POST'));

    expect($laterCallbackRan)->toBeTrue();
});
