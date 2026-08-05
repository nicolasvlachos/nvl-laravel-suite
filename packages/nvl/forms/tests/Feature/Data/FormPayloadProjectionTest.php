<?php

declare(strict_types=1);

use Nvl\Forms\Data\AllowedOriginPayload;
use Nvl\Forms\Data\FormAnalyticPayload;
use Nvl\Forms\Data\FormEntryPayload;
use Nvl\Forms\Data\FormRateLimitPayload;
use Nvl\Forms\Enums\FormAnalyticEventType;
use Nvl\Forms\Models\AllowedOrigin;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormAnalytic;
use Nvl\Forms\Models\FormEntry;
use Nvl\Forms\Models\FormRateLimit;

test('stored form models project into complete consumer payloads', function (): void {
    $form = Form::factory()->create(['handle' => 'projection-form']);
    $origin = AllowedOrigin::factory()->for($form)->create([
        'origin' => 'embed.example.test',
        'description' => 'Primary embed',
        'cors_settings' => [
            'policy' => 'strict',
            'allowCredentials' => true,
            'allowWildcards' => false,
            'maxAge' => 300,
            'allowedMethods' => ['GET', 'POST', 'OPTIONS'],
            'allowedHeaders' => ['Content-Type'],
        ],
        'usage_count' => 4,
        'last_used_at' => now(),
    ])->load('form');
    $entry = FormEntry::factory()->for($form)->create([
        'subject' => 'Projected entry',
        'email' => 'projection@example.test',
        'security_flags' => ['reviewed' => true],
        'spam_score' => 12,
    ])->load('form');
    $analytic = FormAnalytic::query()->create([
        'form_id' => $form->id,
        'event_type' => FormAnalyticEventType::SUBMISSION,
        'origin' => 'embed.example.test',
        'ip_address' => '192.0.2.20',
        'user_agent' => 'Consumer/1.0',
        'session_id' => 'session-projection',
        'metadata' => ['campaign' => 'launch'],
    ])->load('form');
    $rateLimit = FormRateLimit::query()->create([
        'form_id' => $form->id,
        'ip_address' => '192.0.2.20',
        'submission_count' => 3,
        'window_start' => now()->subMinutes(10),
        'last_submission_at' => now(),
        'is_blocked' => true,
        'blocked_until' => now()->addMinutes(5),
        'violation_count' => 2,
    ])->load('form');

    $originPayload = AllowedOriginPayload::fromModel($origin);
    $entryPayload = FormEntryPayload::fromModel($entry);
    $analyticPayload = FormAnalyticPayload::fromModel($analytic);
    $rateLimitPayload = FormRateLimitPayload::fromModel($rateLimit);

    expect($originPayload->id)->toBe($origin->id)
        ->and($originPayload->corsSettings?->policy->value)->toBe('strict')
        ->and($originPayload->usageCount)->toBe(4)
        ->and($originPayload->defaultWrap())->toBe('allowed_origin')
        ->and($entryPayload->id)->toBe($entry->id)
        ->and($entryPayload->subject)->toBe('Projected entry')
        ->and($entryPayload->securityFlags)->toBe(['reviewed' => true])
        ->and($analyticPayload->eventType)->toBe(FormAnalyticEventType::SUBMISSION)
        ->and($analyticPayload->metadata)->toBe(['campaign' => 'launch'])
        ->and($analyticPayload->defaultWrap())->toBe('form_analytic')
        ->and($rateLimitPayload->ipAddress)->toBe('192.0.2.20')
        ->and($rateLimitPayload->submissionCount)->toBe(3)
        ->and($rateLimitPayload->isBlocked)->toBeTrue()
        ->and($rateLimitPayload->defaultWrap())->toBe('form_rate_limit');
});

test('payload projections preserve null and default database states', function (): void {
    $form = Form::factory()->create();
    $origin = AllowedOrigin::factory()->for($form)->create([
        'cors_settings' => null,
        'usage_count' => 0,
        'last_used_at' => null,
    ]);
    $entry = FormEntry::factory()->for($form)->create([
        'is_spam' => false,
        'spam_score' => null,
        'security_flags' => null,
    ]);
    $analytic = FormAnalytic::query()->create([
        'form_id' => $form->id,
        'event_type' => FormAnalyticEventType::VIEW,
        'origin' => null,
        'ip_address' => null,
        'metadata' => null,
    ]);
    $rateLimit = FormRateLimit::query()->create([
        'form_id' => $form->id,
        'ip_address' => '198.51.100.20',
        'submission_count' => 0,
        'window_start' => now(),
        'last_submission_at' => now(),
        'is_blocked' => false,
        'blocked_until' => null,
        'violation_count' => 0,
    ]);

    expect(AllowedOriginPayload::fromModel($origin)->corsSettings)->toBeNull()
        ->and(FormEntryPayload::fromModel($entry)->isSpam)->toBeFalse()
        ->and(FormAnalyticPayload::fromModel($analytic)->metadata)->toBeNull()
        ->and(FormRateLimitPayload::fromModel($rateLimit)->isBlocked)->toBeFalse();
});
