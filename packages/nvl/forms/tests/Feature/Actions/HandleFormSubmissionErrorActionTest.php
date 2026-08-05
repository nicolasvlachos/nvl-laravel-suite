<?php

declare(strict_types=1);

use Nvl\Forms\Actions\Form\HandleFormSubmissionErrorAction;

test('handle form submission error action maps host errors', function (): void {
    $action = app(HandleFormSubmissionErrorAction::class);

    $message = $action->execute(new Exception('Submission not allowed from this host'));

    expect($message)->toBe(trans('forms::forms/messages.error.host_not_allowed'));
});

test('handle form submission error action falls back to generic message', function (): void {
    $action = app(HandleFormSubmissionErrorAction::class);

    $message = $action->execute(new Exception('Unexpected failure'));

    expect($message)->toBe(trans('forms::forms/messages.error.submission_failed'));
});
