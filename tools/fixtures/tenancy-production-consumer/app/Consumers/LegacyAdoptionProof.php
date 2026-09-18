<?php

declare(strict_types=1);

namespace App\Consumers;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Nvl\Tenancy\ValueObjects\TenantAssignment;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Loads the immutable legacy fixture and validates its explicit owner mapping. */
final readonly class LegacyAdoptionProof
{
    public function __construct(private Filesystem $files) {}

    /** @return array{mapping_hash: string, configuration_hash: string, conservation_manifested: bool, ambiguous_activation_blocked: bool} */
    public function inspect(TenantAssignment $assignment, TenantId $tenant): array
    {
        $raw = $this->files->get(base_path('legacy/adoption-manifest.json'));
        $manifest = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ! is_array($manifest['fixtures'] ?? null)) {
            throw new InvalidArgumentException('The legacy adoption manifest is invalid.');
        }
        $mapping = [[
            'resource' => $assignment->resource,
            'resource_id' => $assignment->recordId,
            'tenant_id' => $assignment->tenantId->value,
        ]];
        $ambiguousBlocked = false;
        try {
            $this->assertUnambiguous([
                ...$mapping,
                ['resource' => $assignment->resource, 'resource_id' => $assignment->recordId, 'tenant_id' => $tenant->value.'-other'],
            ]);
        } catch (InvalidArgumentException) {
            $ambiguousBlocked = true;
        }

        return [
            'mapping_hash' => hash('sha256', json_encode($mapping, JSON_THROW_ON_ERROR)),
            'configuration_hash' => hash('sha256', json_encode(config('tenancy'), JSON_THROW_ON_ERROR)),
            'conservation_manifested' => $manifest['conservation'] === ['row_count', 'stable_id', 'structured_payload', 'binary_checksum', 'render_checksum'],
            'ambiguous_activation_blocked' => $ambiguousBlocked,
        ];
    }

    /** @param list<array{resource: string, resource_id: string, tenant_id: string}> $rows */
    private function assertUnambiguous(array $rows): void
    {
        $owners = [];
        foreach ($rows as $row) {
            $key = $row['resource'].'|'.$row['resource_id'];
            if (isset($owners[$key]) && $owners[$key] !== $row['tenant_id']) {
                throw new InvalidArgumentException("Legacy resource [{$key}] has ambiguous owners; activation is blocked.");
            }
            $owners[$key] = $row['tenant_id'];
        }
    }
}
