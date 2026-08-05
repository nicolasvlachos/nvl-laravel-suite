<?php

declare(strict_types=1);

namespace App\Http\Resources\AuthManagement;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Presents one synchronized application permission.
 */
final class PermissionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'name' => $this->resource->name,
            'guard' => $this->resource->guard_name,
        ];
    }
}
