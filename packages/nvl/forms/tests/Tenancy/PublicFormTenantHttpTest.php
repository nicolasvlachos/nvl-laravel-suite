<?php

declare(strict_types=1);

use Nvl\Forms\Services\PublicFormTokenService;
use Nvl\Forms\Tests\Fixtures\TenantScenario;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\TenantId;

test('public tokens bind both tenant and verified site', function (): void {
    $runner = app(TenantRunner::class);
    $form = $runner->run(new TenantId(TenantScenario::A), fn () => createTenantForm('site-contact'));
    $token = $runner->run(new TenantId(TenantScenario::A), fn () => app(PublicFormTokenService::class)->issue($form, now()->addMinute(), 'a'));

    expect($runner->run(
        new TenantId(TenantScenario::A),
        fn () => app(PublicFormTokenService::class)->validate($token, $form, 'b'),
    ))->toBeFalse();
});
