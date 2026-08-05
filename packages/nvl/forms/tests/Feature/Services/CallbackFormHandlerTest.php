<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Services\CallbackFormHandler;

test('callback form handler executes the supplied closure', function (): void {
    $form = Form::factory()->create();
    $request = Request::create('/forms/'.$form->id.'/submit', 'POST', [
        'subject' => 'Callback run',
    ]);
    $request->headers->set('User-Agent', 'Pest/Callback');

    $captured = [];

    $handler = new CallbackFormHandler(function (Form $incomingForm, array $payload, Request $incomingRequest) use (&$captured) {
        $captured = [
            'form_id' => $incomingForm->id,
            'payload' => $payload,
            'user_agent' => $incomingRequest->userAgent(),
        ];

        return [
            'entry_id' => 'callback-'.str_replace(' ', '-', strtolower($payload['subject'])),
        ];
    });

    $result = $handler->handle($form, ['subject' => 'Callback run'], $request);

    expect($captured)
        ->toMatchArray([
            'form_id' => $form->id,
            'payload' => ['subject' => 'Callback run'],
            'user_agent' => 'Pest/Callback',
        ]);

    expect($result)->toMatchArray([
        'entry_id' => 'callback-callback-run',
    ]);
});
