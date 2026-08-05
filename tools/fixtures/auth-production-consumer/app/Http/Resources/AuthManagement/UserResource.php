<?php

declare(strict_types=1);

namespace App\Http\Resources\AuthManagement;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Nvl\Auth\Models\Principal;

/**
 * Presents host identity, business access, and safe package principal state.
 */
final class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $principal = $this->resource->authPrincipal;

        return [
            'id' => $this->resource->getKey(),
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'roles' => $this->resource->roles->pluck('name')->values()->all(),
            'directPermissions' => $this->resource->permissions
                ->pluck('name')
                ->values()
                ->all(),
            'principal' => $principal instanceof Principal ? [
                'id' => $principal->id,
                'status' => $principal->status->value,
                'securityVersion' => $principal->security_version,
            ] : null,
            'createdAt' => $this->resource->created_at?->toAtomString(),
            'updatedAt' => $this->resource->updated_at?->toAtomString(),
        ];
    }
}
