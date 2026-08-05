<?php

declare(strict_types=1);

use Nvl\Forms\Actions\AllowedOrigin\RecordAllowedOriginUsageAction;
use Nvl\Forms\Models\AllowedOrigin;

test('record allowed origin usage action increments counter and timestamps', function (): void {
    $origin = AllowedOrigin::factory()->create([
        'usage_count' => 0,
        'last_used_at' => null,
    ]);

    app(RecordAllowedOriginUsageAction::class)->execute($origin);

    $origin->refresh();

    expect($origin->usage_count)->toBe(1)
        ->and($origin->last_used_at)->not->toBeNull();
});

test('record allowed origin usage action increments from existing count', function (): void {
    $origin = AllowedOrigin::factory()->create([
        'usage_count' => 5,
    ]);

    app(RecordAllowedOriginUsageAction::class)->execute($origin);

    expect($origin->fresh()->usage_count)->toBe(6);
});
