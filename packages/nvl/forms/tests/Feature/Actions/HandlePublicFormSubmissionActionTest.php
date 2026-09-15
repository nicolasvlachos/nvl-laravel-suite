<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Nvl\Forms\Actions\Form\HandlePublicFormSubmissionAction;
use Nvl\Forms\Contracts\FormSpamDetector;
use Nvl\Forms\Data\Mutations\SubmitFormPayload;
use Nvl\Forms\Enums\Resolvement;
use Nvl\Forms\Exceptions\FormSubmissionRejectionException;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;
use Nvl\Forms\Models\FormRateLimit;
use Nvl\Forms\Results\FormSubmissionResult;
use Nvl\Forms\Services\EntryCallbackRegistry;
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

test('entry callbacks wait for the surrounding transaction and disappear on rollback', function (bool $commit): void {
    $form = Form::factory()->create([
        'handle' => 'transactional-callback',
        'resolvement' => Resolvement::ENTRIES,
        'enable_honeypot' => false,
        'enable_rate_limiting' => false,
        'restrict_public_access' => false,
        'require_csrf' => false,
    ]);
    $observedEntries = [];
    app(EntryCallbackRegistry::class)->register($form->handle, function (Form $form, FormEntry $entry) use (&$observedEntries): void {
        $observedEntries[] = FormEntry::query()->findOrFail($entry->id)->id;
    });

    DB::beginTransaction();
    try {
        $result = app(HandlePublicFormSubmissionAction::class)->execute(
            $form,
            SubmitFormPayload::from(['email' => 'transaction@example.com']),
            formSubmissionContext(Request::create('/submit', 'POST')),
        );
        expect($observedEntries)->toBe([]);
    } catch (Throwable $exception) {
        DB::rollBack();

        throw $exception;
    }

    if ($commit) {
        DB::commit();
        expect($observedEntries)->toBe([$result->entryId]);
    } else {
        DB::rollBack();
        expect($observedEntries)->toBe([])
            ->and(FormEntry::query()->whereKey($result->entryId)->exists())->toBeFalse();
    }
})->with(['commit' => true, 'rollback' => false]);

test('public submission honors an interface-only configured spam detector', function (Resolvement $resolvement, bool $honeypot): void {
    $form = Form::factory()->create([
        'handle' => 'configured-detector',
        'resolvement' => $resolvement,
        'enable_honeypot' => true,
        'enable_rate_limiting' => false,
        'restrict_public_access' => false,
        'require_csrf' => false,
    ]);
    app()->instance(FormSpamDetector::class, new class($honeypot) implements FormSpamDetector
    {
        public function __construct(private readonly bool $honeypot) {}

        public function checkHoneypot(Form $form, array $data): bool
        {
            return $this->honeypot;
        }

        public function calculateSpamScore(Form $form, array $data, string $ipAddress, ?string $userAgent = null, ?FormRateLimit $rateLimit = null, ?float $formLoadTime = null): float
        {
            return 65;
        }

        public function shouldBlockSubmission(float $spamScore): bool
        {
            return $spamScore >= 60;
        }

        public function shouldFlagSubmission(float $spamScore): bool
        {
            return $spamScore >= 20 && $spamScore < 60;
        }
    });
    $handlerCalls = 0;
    app(FormHandlerRegistry::class)->register($form->handle, function () use (&$handlerCalls): array {
        $handlerCalls++;

        return ['entry_id' => 'unexpected-handler-result'];
    });
    $submit = fn () => app(HandlePublicFormSubmissionAction::class)->execute(
        $form,
        SubmitFormPayload::from(['email' => 'safe@example.com', 'body' => 'Hello']),
        formSubmissionContext(Request::create('/submit', 'POST', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0'])),
    );

    if ($honeypot || $resolvement === Resolvement::CUSTOM) {
        expect($submit)->toThrow(Exception::class);
        expect(FormEntry::query()->count())->toBe(0);
    } else {
        $result = $submit();
        $entry = FormEntry::query()->findOrFail($result->entryId);
        expect($entry->is_spam)->toBeTrue()->and($entry->spam_score)->toBe(65);
    }

    expect($handlerCalls)->toBe(0);
})->with([
    'entry scoring' => [Resolvement::ENTRIES, false],
    'custom scoring' => [Resolvement::CUSTOM, false],
    'entry honeypot' => [Resolvement::ENTRIES, true],
    'custom honeypot' => [Resolvement::CUSTOM, true],
]);
