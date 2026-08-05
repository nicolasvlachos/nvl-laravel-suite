<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Services\ActionBackedHandler;

test('action backed handler proxies execution through the container', function (): void {
    $form = Form::factory()->create();

    $request = Request::create('/forms/'.$form->id.'/submit', 'POST', [
        'subject' => 'Demo',
    ]);

    $action = new class
    {
        /** @var array<string, mixed> */
        public array $captured = [];

        /**
         * @return array<string, mixed>
         */
        public function execute(Form $form, array $data, Request $request): array
        {
            $this->captured = [
                'form_id' => $form->id,
                'payload' => $data,
                'request_ip' => $request->ip(),
            ];

            return [
                'entry_id' => 'action-'.$data['subject'],
                'meta' => ['handled_by' => 'execute'],
            ];
        }
    };

    $handler = new ActionBackedHandler($action, 'execute');

    $result = $handler->handle($form, ['subject' => 'Demo'], $request);

    expect($action->captured['form_id'])->toBe($form->id)
        ->and($action->captured['payload'])->toMatchArray(['subject' => 'Demo'])
        ->and($result)->toMatchArray([
            'entry_id' => 'action-Demo',
            'meta' => ['handled_by' => 'execute'],
        ]);
});

test('action backed handler fails when action result is not an array', function (): void {
    $form = Form::factory()->create();
    $request = Request::create('/forms/'.$form->id.'/submit', 'POST');
    $action = new class
    {
        public function execute(Form $form, array $data, Request $request): string
        {
            return 'invalid';
        }
    };

    $handler = new ActionBackedHandler($action, 'execute');

    expect(fn () => $handler->handle($form, [], $request))
        ->toThrow(LogicException::class, 'must return an array');
});
