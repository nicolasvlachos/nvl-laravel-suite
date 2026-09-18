<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Services\TenantOwnershipConfiguration;
use Throwable;

/** Executes one configuration profile in its own application process. */
final class TenancyConsumerConfigurationCommand extends Command
{
    /** @var string */
    protected $signature = 'tenancy-consumer:configuration {profile} {--format=json}';

    /** @var string */
    protected $description = 'Verify a fresh-process tenancy configuration profile';

    /** @var list<string> */
    private const array INVALID_PROFILES = [
        'conflicting-platform-family',
        'invalid-classes',
        'invalid-families',
        'invalid-custom-tables',
        'invalid-connection-aliases',
    ];

    public function handle(TenantOwnershipConfiguration $ownership, TenantContext $context): int
    {
        $profile = $this->argument('profile');
        if (! is_string($profile)) {
            throw new \InvalidArgumentException('The configuration profile must be a string.');
        }
        $error = null;
        try {
            if (config('tenancy.enabled') === true) {
                $ownership->assertReady();
            }
        } catch (Throwable $throwable) {
            $error = $throwable::class;
        }
        $expectsFailure = in_array($profile, self::INVALID_PROFILES, true);
        $passed = $expectsFailure ? $error !== null : $error === null;
        $result = [
            'profile' => $profile,
            'passed' => $passed,
            'expects_failure' => $expectsFailure,
            'error' => $error,
            'enabled' => config('tenancy.enabled') === true,
            'context_mode' => $context->snapshot()->mode->value,
            'unresolved_fails_closed' => $context->snapshot()->mode === TenantContextMode::Unresolved,
        ];
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
