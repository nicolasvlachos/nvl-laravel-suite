<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Nvl\Forms\Actions\Form\HandlePublicFormSubmissionAction;
use Nvl\Forms\Data\Mutations\SubmitFormPayload;
use Nvl\Forms\Enums\Resolvement;
use Nvl\Forms\Exceptions\FormSubmissionRejectionException;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;
use Nvl\Forms\Results\FormSubmissionResult;
use Nvl\Forms\Support\FormHandlerRegistry;

test('handle public form submission creates entry for entries resolvement', function (): void {
    $form = Form::factory()->create([
        'resolvement' => Resolvement::ENTRIES,
        'enable_honeypot' => false,
        'enable_rate_limiting' => false,
        'restrict_public_access' => false,
        'require_csrf' => false,
        'submissions_count' => 0,
    ]);

    $data = SubmitFormPayload::from([
        'firstName' => 'John',
        'lastName' => 'Doe',
        'email' => 'john@example.com',
        'body' => 'Hello, this is a test submission.',
        'submittedFrom' => 'https://example.com',
    ]);

    $request = Request::create('/submit', 'POST');
    $request->server->set('REMOTE_ADDR', '10.0.0.1');

    $result = app(HandlePublicFormSubmissionAction::class)->execute($form, $data, formSubmissionContext($request));

    expect($result)->toBeInstanceOf(FormSubmissionResult::class)
        ->and($result->entryId)->not->toBeEmpty()
        ->and($result->form->id)->toBe($form->id);

    $this->assertDatabaseHas(FormEntry::query()->getModel()->getTable(), [
        'form_id' => $form->id,
        'email' => 'john@example.com',
    ]);
});

test('handle public form submission increments counters', function (): void {
    $form = Form::factory()->create([
        'resolvement' => Resolvement::ENTRIES,
        'enable_honeypot' => false,
        'enable_rate_limiting' => false,
        'restrict_public_access' => false,
        'require_csrf' => false,
        'submissions_count' => 0,
        'views_count' => 0,
    ]);

    $data = SubmitFormPayload::from([
        'email' => 'counter@example.com',
        'submittedFrom' => 'https://example.com',
    ]);

    $request = Request::create('/submit', 'POST');
    $request->server->set('REMOTE_ADDR', '10.0.0.2');

    app(HandlePublicFormSubmissionAction::class)->execute($form, $data, formSubmissionContext($request));

    expect($form->fresh()->submissions_count)->toBe(1);
});

test('handle public form submission delegates to custom handler', function (): void {
    $form = Form::factory()->create([
        'resolvement' => Resolvement::CUSTOM,
        'handle' => 'test-custom-handle',
        'enable_honeypot' => false,
        'enable_rate_limiting' => false,
        'restrict_public_access' => false,
        'require_csrf' => false,
    ]);

    // Register a callable handler — CustomFormRegistry resolves callables via CallbackFormHandler
    $registry = app(FormHandlerRegistry::class);
    $registry->register('test-custom-handle', function (Form $form, array $data, Request $request): array {
        return ['entry_id' => 'custom-123', 'meta' => ['handled' => true]];
    });

    $data = SubmitFormPayload::from([
        'email' => 'custom@example.com',
        'submittedFrom' => 'https://example.com',
    ]);

    $request = Request::create('/submit', 'POST');
    $request->server->set('REMOTE_ADDR', '10.0.0.3');

    $result = app(HandlePublicFormSubmissionAction::class)->execute($form, $data, formSubmissionContext($request));

    expect($result)->toBeInstanceOf(FormSubmissionResult::class)
        ->and($result->entryId)->toBe('custom-123')
        ->and($result->hasBookkeepingWarning)->toBeFalse();
});

test('handle public form submission executes custom handler outside a forms-owned transaction', function (): void {
    $form = Form::factory()->create([
        'resolvement' => Resolvement::CUSTOM,
        'handle' => 'custom-no-outer-transaction',
        'enable_honeypot' => false,
        'enable_rate_limiting' => false,
        'restrict_public_access' => false,
        'require_csrf' => false,
    ]);

    $observedTransactionLevel = null;
    $baselineTransactionLevel = DB::transactionLevel();

    $registry = app(FormHandlerRegistry::class);
    $registry->register('custom-no-outer-transaction', function (Form $form, array $data, Request $request) use (&$observedTransactionLevel): array {
        $observedTransactionLevel = DB::transactionLevel();

        return ['entry_id' => 'custom-no-outer-transaction'];
    });

    $data = SubmitFormPayload::from([
        'email' => 'custom@example.com',
        'submittedFrom' => 'https://example.com',
    ]);

    $request = Request::create('/submit', 'POST');
    $request->server->set('REMOTE_ADDR', '10.0.0.4');

    $result = app(HandlePublicFormSubmissionAction::class)->execute($form, $data, formSubmissionContext($request));

    expect($result->entryId)->toBe('custom-no-outer-transaction')
        ->and($observedTransactionLevel)->toBe($baselineTransactionLevel);
});

test('handle public form submission preserves custom handler success when forms bookkeeping fails', function (): void {
    $form = Form::factory()->create([
        'resolvement' => Resolvement::CUSTOM,
        'handle' => 'custom-bookkeeping-failure',
        'enable_honeypot' => false,
        'enable_rate_limiting' => false,
        'restrict_public_access' => false,
        'require_csrf' => false,
    ]);

    $registry = app(FormHandlerRegistry::class);
    $registry->register('custom-bookkeeping-failure', function (Form $form, array $data, Request $request): array {
        return ['entry_id' => 'custom-bookkeeping-failure'];
    });

    DB::shouldReceive('transaction')
        ->once()
        ->andThrow(new RuntimeException('Forms bookkeeping failed'));

    $data = SubmitFormPayload::from([
        'email' => 'custom@example.com',
        'submittedFrom' => 'https://example.com',
    ]);

    $request = Request::create('/submit', 'POST');
    $request->server->set('REMOTE_ADDR', '10.0.0.5');

    $result = app(HandlePublicFormSubmissionAction::class)->execute($form, $data, formSubmissionContext($request));

    expect($result->entryId)->toBe('custom-bookkeeping-failure')
        ->and($result->hasBookkeepingWarning)->toBeTrue();
});

test('handle public form submission throws when custom handler not registered', function (): void {
    $form = Form::factory()->create([
        'resolvement' => Resolvement::CUSTOM,
        'handle' => 'nonexistent-handle',
        'require_csrf' => false,
    ]);

    $data = SubmitFormPayload::from([
        'email' => 'test@example.com',
    ]);

    $request = Request::create('/submit', 'POST');

    expect(fn () => app(HandlePublicFormSubmissionAction::class)->execute($form, $data, formSubmissionContext($request)))
        ->toThrow(FormSubmissionRejectionException::class);
});
