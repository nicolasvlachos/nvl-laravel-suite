<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Nvl\Csv\Services\CSVAsyncProcessor;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;

test('tenant csv refuses arbitrary serialized callbacks before staging', function (): void {
    Config::set('tenancy.enabled', true);
    Storage::fake('local');
    $processor = CSVAsyncProcessor::make();

    expect(fn () => $processor->processRow(static fn (array $row): null => null))
        ->toThrow(TenantBoundaryViolation::class)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});
