<?php

declare(strict_types=1);

namespace Nvl\Csv\Services;

use Illuminate\Support\Facades\Storage;
use JsonException;
use Nvl\Csv\ValueObjects\CSVWorkReference;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use RuntimeException;

/** Owns bounded JSON manifests and verifies their exact tenant/work identity. */
final readonly class CSVWorkStore
{
    public function __construct(private TenantContext $context) {}

    /** @param array<string,mixed> $manifest */
    public function write(CSVWorkReference $work, array $manifest): void
    {
        $this->assertReference($work);
        $this->assertJsonOnly($manifest);
        $payload = [
            'work_id' => $work->workId,
            'tenant_id' => $work->tenantId,
            'handler_alias' => $work->handlerAlias,
            'handler_version' => $work->handlerVersion,
            'manifest' => $manifest,
        ];
        $payload['checksum'] = $this->checksum($payload);
        if (! Storage::disk($work->disk)->put($work->manifestPath, json_encode($payload, JSON_THROW_ON_ERROR), ['visibility' => 'private'])) {
            throw new RuntimeException('Unable to persist CSV work manifest.');
        }
    }

    /** @return array<string,mixed> */
    public function read(CSVWorkReference $work): array
    {
        $this->assertReference($work);
        $raw = Storage::disk($work->disk)->get($work->manifestPath);
        if (! is_string($raw)) {
            throw new RuntimeException('CSV work manifest is missing.');
        }
        try {
            $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new TenantBoundaryViolation('CSV work manifest is invalid: '.$exception->getMessage());
        }
        if (! is_array($payload) || ($payload['work_id'] ?? null) !== $work->workId
            || ($payload['tenant_id'] ?? null) !== $work->tenantId
            || ($payload['handler_alias'] ?? null) !== $work->handlerAlias
            || ($payload['handler_version'] ?? null) !== $work->handlerVersion) {
            throw new TenantBoundaryViolation('CSV work manifest identity does not match its reference.');
        }
        $checksum = $payload['checksum'] ?? null;
        $manifest = $payload['manifest'] ?? null;
        $checksumPayload = [
            'work_id' => $work->workId,
            'tenant_id' => $work->tenantId,
            'handler_alias' => $work->handlerAlias,
            'handler_version' => $work->handlerVersion,
            'manifest' => $manifest,
        ];
        if (! is_string($checksum) || ! hash_equals($checksum, $this->checksum($checksumPayload))) {
            throw new TenantBoundaryViolation('CSV work manifest checksum is invalid.');
        }
        if (! is_array($manifest)) {
            throw new TenantBoundaryViolation('CSV work manifest payload is invalid.');
        }

        $normalized = [];
        foreach ($manifest as $key => $value) {
            if (! is_string($key)) {
                throw new TenantBoundaryViolation('CSV work manifest payload keys are invalid.');
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    public function delete(CSVWorkReference $work): void
    {
        $this->assertReference($work);
        $this->read($work);
        Storage::disk($work->disk)->deleteDirectory(dirname($work->manifestPath));
    }

    private function assertReference(CSVWorkReference $work): void
    {
        $tenant = $this->context->requireTenant()->value;
        $expected = 'tenants/'.$tenant.'/csv/'.$work->workId.'/manifest.json';
        if ($work->tenantId !== $tenant || $work->manifestPath !== $expected
            || preg_match('/^[0-9a-f-]{36}$/i', $work->workId) !== 1
            || $work->disk === '' || $work->handlerAlias === '' || $work->handlerVersion < 1) {
            throw new TenantBoundaryViolation('CSV work reference does not belong to the active tenant.');
        }
    }

    /** @param array<string,mixed> $payload */
    private function checksum(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function assertJsonOnly(mixed $value): void
    {
        if (is_null($value) || is_scalar($value)) {
            return;
        }
        if (! is_array($value)) {
            throw new TenantBoundaryViolation('CSV work manifests may contain JSON values only.');
        }
        foreach ($value as $item) {
            $this->assertJsonOnly($item);
        }
    }
}
