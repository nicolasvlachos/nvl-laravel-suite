<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Nvl\Forms\Contracts\CustomFormHandler;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Services\ActionBackedHandler;
use Nvl\Forms\Services\CallbackFormHandler;
use Nvl\Forms\Services\CustomFormRegistry;
use Nvl\Forms\Support\FormHandlerRegistry;

test('custom form registry returns configured handler instances', function (): void {
    $form = Form::factory()->create(['handle' => 'registration']);
    $handlerRegistry = app(FormHandlerRegistry::class);
    $handlerRegistry->clear();

    $handler = new class implements CustomFormHandler
    {
        public bool $resolved = false;

        public function handle(Form $form, array $data, Request $request): array
        {
            $this->resolved = true;

            return ['entry_id' => 'direct-handler'];
        }
    };

    $class = get_class($handler);

    app()->bind($class, static fn () => $handler);

    $handlerRegistry->register($form->handle, $class);

    $registry = new CustomFormRegistry(app(), $handlerRegistry);

    $resolved = $registry->resolve($form);

    expect($resolved)->toBe($handler);

    $resolved?->handle($form, [], Request::create('/forms/'.$form->id.'/submit', 'POST'));

    expect($handler->resolved)->toBeTrue();

    $handlerRegistry->clear();
});

test('custom form registry wraps callable classes in action backed handlers', function (): void {
    $form = Form::factory()->create(['handle' => 'custom-action']);
    $handlerRegistry = app(FormHandlerRegistry::class);
    $handlerRegistry->clear();

    $action = new class
    {
        public array $captured = [];

        public function execute(Form $form, array $data, Request $request): array
        {
            $this->captured = [
                'form' => $form->id,
                'subject' => $data['subject'] ?? null,
            ];

            return ['entry_id' => 'from-action'];
        }
    };

    $class = get_class($action);

    app()->bind($class, static fn () => $action);

    $handlerRegistry->register($form->handle, $class);

    $registry = new CustomFormRegistry(app(), $handlerRegistry);

    $resolved = $registry->resolve($form);

    expect($resolved)->toBeInstanceOf(ActionBackedHandler::class);

    $result = $resolved?->handle($form, ['subject' => 'Demo custom'], Request::create('/forms/'.$form->id.'/submit', 'POST'));

    expect($result)->toMatchArray(['entry_id' => 'from-action']);
    expect($action->captured)->toMatchArray([
        'form' => $form->id,
        'subject' => 'Demo custom',
    ]);

    $handlerRegistry->clear();
});

test('custom form registry wraps closures with callback handlers', function (): void {
    $form = Form::factory()->create(['handle' => 'closure-form']);
    $handlerRegistry = app(FormHandlerRegistry::class);
    $handlerRegistry->clear();

    $calls = 0;

    $handlerRegistry->register($form->handle, static function (Form $incomingForm, array $payload, Request $request) use (&$calls): array {
        $calls++;

        return [
            'entry_id' => 'callback-'.$incomingForm->id,
        ];
    });

    $registry = new CustomFormRegistry(app(), $handlerRegistry);

    $resolved = $registry->resolve($form);

    expect($resolved)->toBeInstanceOf(CallbackFormHandler::class);

    $result = $resolved?->handle($form, [], Request::create('/forms/'.$form->id.'/submit', 'POST'));

    expect($calls)->toBe(1);
    expect($result)->toMatchArray(['entry_id' => 'callback-'.$form->id]);

    $handlerRegistry->clear();
});

test('custom form registry returns null when no handler is configured', function (): void {
    $form = Form::factory()->create(['handle' => 'missing-handler']);
    $handlerRegistry = app(FormHandlerRegistry::class);
    $handlerRegistry->clear();

    $registry = new CustomFormRegistry(app(), $handlerRegistry);

    expect($registry->resolve($form))->toBeNull();
});
