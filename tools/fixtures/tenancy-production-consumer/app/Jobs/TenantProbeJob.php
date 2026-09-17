<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Contracts\TenantQueuedJob;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;
use RuntimeException;
use Throwable;

/** Writes scalar queue observations under the restored tenant envelope. */
final class TenantProbeJob implements ShouldQueue, TenantQueuedJob
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Create one scalar tenant probe. */
    public function __construct(
        public readonly string $probe,
        private readonly bool $shouldFail,
        private readonly TenantJobEnvelope $envelope,
    ) {}

    /** Return the immutable producer-captured tenant envelope. */
    public function tenantJobEnvelope(): TenantJobEnvelope
    {
        return $this->envelope;
    }

    /** Record normal execution and optionally force the failure callback. */
    public function handle(TenantContext $context): void
    {
        DB::table('tenant_probe_observations')->insert([
            'probe' => $this->probe,
            'phase' => 'handle',
            'tenant_id' => $context->requireTenant()->value,
            'created_at' => now(),
        ]);

        if ($this->shouldFail) {
            throw new RuntimeException('Intentional tenancy consumer failure.');
        }
    }

    /** Record the tenant restored before Laravel invokes the failure callback. */
    public function failed(?Throwable $exception): void
    {
        DB::table('tenant_probe_observations')->insert([
            'probe' => $this->probe,
            'phase' => 'failed',
            'tenant_id' => app(TenantContext::class)->requireTenant()->value,
            'created_at' => now(),
        ]);
    }
}
