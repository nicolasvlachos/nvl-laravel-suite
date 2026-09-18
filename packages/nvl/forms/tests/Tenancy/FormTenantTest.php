<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Nvl\Forms\Actions\Form\CreateFormAction;
use Nvl\Forms\Actions\Form\GetFormForRenderAction;
use Nvl\Forms\Data\Mutations\MutateFormPayload;
use Nvl\Forms\Enums\FormType;
use Nvl\Forms\Enums\Resolvement;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Tests\Fixtures\TenantScenario;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\TenantId;

function createTenantForm(string $handle): Form
{
    return app(CreateFormAction::class)->execute(MutateFormPayload::from([
        'handle' => $handle,
        'translations' => ['en' => ['name' => 'Contact']],
        'resolvement' => Resolvement::ENTRIES->value,
        'type' => FormType::IFRAME->value,
    ]));
}

test('a supplied foreign form is canonically denied while handles remain tenant local', function (): void {
    $runner = app(TenantRunner::class);
    $formFromB = $runner->run(new TenantId(TenantScenario::B), fn () => createTenantForm('contact'));
    $runner->run(new TenantId(TenantScenario::A), fn () => createTenantForm('contact'));

    expect(fn () => $runner->run(
        new TenantId(TenantScenario::A),
        fn () => app(GetFormForRenderAction::class)->execute($formFromB),
    ))->toThrow(ModelNotFoundException::class);
});
