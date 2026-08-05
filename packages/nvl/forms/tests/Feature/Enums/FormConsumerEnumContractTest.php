<?php

declare(strict_types=1);

use Nvl\Forms\Enums\CorsPolicy;
use Nvl\Forms\Enums\FormAnalyticEventType;
use Nvl\Forms\Enums\FormStatus;

test('form statuses expose complete consumer display and lifecycle contracts', function (): void {
    $contracts = [];

    foreach (FormStatus::cases() as $status) {
        $contracts[$status->value] = [
            'label' => $status->getLabel(),
            'description' => $status->description(),
            'color' => $status->colorClass(),
            'accepts' => $status->canAcceptSubmissions(),
            'editable' => $status->canBeEdited(),
        ];
    }

    expect($contracts['draft'])->toMatchArray(['color' => 'neutral', 'accepts' => false, 'editable' => true])
        ->and($contracts['active'])->toMatchArray(['color' => 'success', 'accepts' => true, 'editable' => false])
        ->and($contracts['paused'])->toMatchArray(['color' => 'warning', 'accepts' => false, 'editable' => true])
        ->and($contracts['archived'])->toMatchArray(['color' => 'danger', 'accepts' => false, 'editable' => false])
        ->and(FormStatus::options())->toHaveCount(4)
        ->and(array_column(FormStatus::options(), 'value'))->toBe(['draft', 'active', 'paused', 'archived']);
});

test('analytic event types expose complete consumer semantics', function (): void {
    $contracts = [];

    foreach (FormAnalyticEventType::cases() as $eventType) {
        $contracts[$eventType->value] = [
            'label' => $eventType->getLabel(),
            'description' => $eventType->description(),
            'positive' => $eventType->isPositive(),
            'security' => $eventType->isSecurity(),
        ];
    }

    expect($contracts['view'])->toMatchArray(['positive' => true, 'security' => false])
        ->and($contracts['submission'])->toMatchArray(['positive' => true, 'security' => false])
        ->and($contracts['spam_blocked'])->toMatchArray(['positive' => false, 'security' => true])
        ->and($contracts['rate_limited'])->toMatchArray(['positive' => false, 'security' => true])
        ->and($contracts['error'])->toMatchArray(['positive' => false, 'security' => false])
        ->and($contracts['validation_failed'])->toMatchArray(['positive' => false, 'security' => false])
        ->and(FormAnalyticEventType::options())->toHaveCount(6);
});

test('cors policies expose safe deterministic defaults for consumers', function (): void {
    $contracts = [];

    foreach (CorsPolicy::cases() as $policy) {
        $contracts[$policy->value] = [
            'label' => $policy->getLabel(),
            'description' => $policy->description(),
            'settings' => $policy->getDefaultSettings(),
            'wildcards' => $policy->supportsWildcards(),
        ];
    }

    expect($contracts['strict']['settings'])->toMatchArray([
        'allowCredentials' => true,
        'allowWildcards' => false,
        'maxAge' => 300,
    ])
        ->and($contracts['moderate']['settings']['maxAge'])->toBe(600)
        ->and($contracts['permissive']['settings'])->toMatchArray([
            'allowCredentials' => false,
            'allowWildcards' => true,
            'maxAge' => 3600,
        ])
        ->and($contracts['custom']['settings']['allowedHeaders'])->toBe(['Content-Type', 'X-Forms-Public-Token'])
        ->and($contracts['permissive']['wildcards'])->toBeTrue()
        ->and($contracts['strict']['wildcards'])->toBeFalse()
        ->and($contracts['moderate']['wildcards'])->toBeFalse()
        ->and($contracts['custom']['wildcards'])->toBeFalse()
        ->and(CorsPolicy::options())->toHaveCount(4);
});
