<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Nvl\Forms\Actions\Form\HandlePublicFormSubmissionAction;
use Nvl\Forms\Actions\FormEntry\CreateFormEntryAction;
use Nvl\Forms\Data\FormEntryPayload;
use Nvl\Forms\Data\Mutations\SubmitFormPayload;
use Nvl\Forms\Enums\Resolvement;
use Nvl\Forms\Events\FormEntryChangedEvent;
use Nvl\Forms\Exceptions\FormSubmissionRejectionException;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;
use Nvl\Forms\Models\FormSubmissionReceipt;
use Nvl\Forms\Services\EntryCallbackRegistry;
use Nvl\Forms\Support\FormHandlerRegistry;

test('submission idempotency returns the original entry without duplicate events', function (): void {
    Event::fake([FormEntryChangedEvent::class]);
    $form = Form::factory()->create([
        'restrict_public_access' => false,
        'enable_rate_limiting' => false,
        'enable_honeypot' => false,
    ]);
    $payload = FormEntryPayload::from([
        'formId' => $form->id,
        'subject' => 'One submission',
        'submittedFrom' => 'example.test',
    ]);

    $first = app(CreateFormEntryAction::class)->execute(
        $payload,
        '127.0.0.1',
        'Mozilla/5.0',
        null,
        null,
        'submission-1',
    );
    $second = app(CreateFormEntryAction::class)->execute(
        $payload,
        '127.0.0.1',
        'Mozilla/5.0',
        null,
        null,
        'submission-1',
    );

    expect($second->id)->toBe($first->id)
        ->and(FormEntry::query()->count())->toBe(1)
        ->and($form->fresh()->submissions_count)->toBe(1);
    Event::assertDispatchedTimes(FormEntryChangedEvent::class, 1);
});

test('submission idempotency rejects reuse with a different payload', function (): void {
    $form = Form::factory()->create([
        'restrict_public_access' => false,
        'enable_rate_limiting' => false,
        'enable_honeypot' => false,
    ]);
    $action = app(CreateFormEntryAction::class);
    $action->execute(
        FormEntryPayload::from([
            'formId' => $form->id,
            'subject' => 'Original',
            'submittedFrom' => 'example.test',
        ]),
        '127.0.0.1',
        null,
        null,
        null,
        'submission-2',
    );

    expect(fn () => $action->execute(
        FormEntryPayload::from([
            'formId' => $form->id,
            'subject' => 'Changed',
            'submittedFrom' => 'example.test',
        ]),
        '127.0.0.1',
        null,
        null,
        null,
        'submission-2',
    ))->toThrow(FormSubmissionRejectionException::class);
});

test('single-registration forms reject a repeated normalized email', function (): void {
    $form = Form::factory()->create([
        'allow_multiple_registrations' => false,
        'restrict_public_access' => false,
        'enable_rate_limiting' => false,
        'enable_honeypot' => false,
    ]);
    $action = app(CreateFormEntryAction::class);

    $action->execute(
        FormEntryPayload::from([
            'formId' => $form->id,
            'email' => 'Person@Example.com',
            'submittedFrom' => 'example.test',
        ]),
        '127.0.0.1',
        null,
        null,
    );

    expect(fn () => $action->execute(
        FormEntryPayload::from([
            'formId' => $form->id,
            'email' => ' person@example.com ',
            'submittedFrom' => 'example.test',
        ]),
        '127.0.0.1',
        null,
        null,
    ))->toThrow(FormSubmissionRejectionException::class);

    expect(FormEntry::query()->count())->toBe(1);
});

test('multiple-registration forms accept repeated email identities', function (): void {
    $form = Form::factory()->create([
        'allow_multiple_registrations' => true,
        'restrict_public_access' => false,
        'enable_rate_limiting' => false,
        'enable_honeypot' => false,
    ]);
    $action = app(CreateFormEntryAction::class);
    $payload = FormEntryPayload::from([
        'formId' => $form->id,
        'email' => 'repeat@example.com',
        'submittedFrom' => 'example.test',
    ]);

    $action->execute($payload, '127.0.0.1', null, null);
    $action->execute($payload, '127.0.0.1', null, null);

    expect(FormEntry::query()->count())->toBe(2);
});

test('custom handler idempotency replays the completed receipt', function (): void {
    $calls = 0;
    $form = Form::factory()->create([
        'handle' => 'custom-idempotency',
        'resolvement' => Resolvement::CUSTOM,
        'allow_multiple_registrations' => true,
        'restrict_public_access' => false,
        'enable_rate_limiting' => false,
        'enable_honeypot' => false,
        'require_csrf' => false,
    ]);

    app(FormHandlerRegistry::class)->register(
        'custom-idempotency',
        function () use (&$calls): array {
            $calls++;

            return ['entry_id' => 'downstream-123'];
        },
    );

    $data = SubmitFormPayload::from(['email' => 'custom@example.com']);
    $request = Request::create('/submit', 'POST', server: [
        'HTTP_IDEMPOTENCY_KEY' => 'custom-submission-1',
    ]);
    $action = app(HandlePublicFormSubmissionAction::class);

    $first = $action->execute($form, $data, formSubmissionContext($request));
    $second = $action->execute($form, $data, formSubmissionContext($request));

    expect($first->entryId)->toBe('downstream-123')
        ->and($second->entryId)->toBe('downstream-123')
        ->and($calls)->toBe(1)
        ->and(FormSubmissionReceipt::query()->count())->toBe(1)
        ->and($form->fresh()->submissions_count)->toBe(1);
});

test('custom handler idempotency rejects a changed payload', function (): void {
    $form = Form::factory()->create([
        'handle' => 'custom-idempotency-conflict',
        'resolvement' => Resolvement::CUSTOM,
        'allow_multiple_registrations' => true,
        'restrict_public_access' => false,
        'enable_rate_limiting' => false,
        'enable_honeypot' => false,
        'require_csrf' => false,
    ]);

    app(FormHandlerRegistry::class)->register(
        'custom-idempotency-conflict',
        static fn (): array => ['entry_id' => 'downstream-456'],
    );

    $request = Request::create('/submit', 'POST', server: [
        'HTTP_IDEMPOTENCY_KEY' => 'custom-submission-2',
    ]);
    $action = app(HandlePublicFormSubmissionAction::class);

    $action->execute(
        $form,
        SubmitFormPayload::from(['subject' => 'Original']),
        formSubmissionContext($request),
    );

    expect(fn () => $action->execute(
        $form,
        SubmitFormPayload::from(['subject' => 'Changed']),
        formSubmissionContext($request),
    ))->toThrow(FormSubmissionRejectionException::class);
});

test('entry submission callbacks do not rerun on an idempotent replay', function (): void {
    $callbackCalls = 0;
    $form = Form::factory()->create([
        'handle' => 'entry-callback-idempotency',
        'restrict_public_access' => false,
        'enable_rate_limiting' => false,
        'enable_honeypot' => false,
        'require_csrf' => false,
    ]);

    app(EntryCallbackRegistry::class)->register(
        'entry-callback-idempotency',
        function () use (&$callbackCalls): void {
            $callbackCalls++;
        },
    );

    $request = Request::create('/submit', 'POST', server: [
        'HTTP_IDEMPOTENCY_KEY' => 'entry-submission-1',
    ]);
    $action = app(HandlePublicFormSubmissionAction::class);
    $data = SubmitFormPayload::from(['email' => 'callback@example.com']);

    $action->execute($form, $data, formSubmissionContext($request));
    $action->execute($form, $data, formSubmissionContext($request));

    expect($callbackCalls)->toBe(1);
});
