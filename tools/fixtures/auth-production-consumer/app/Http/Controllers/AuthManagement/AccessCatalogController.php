<?php

declare(strict_types=1);

namespace App\Http\Controllers\AuthManagement;

use App\Auth\Management\Access\ListPermissionsAction;
use App\Auth\Management\Access\ListRolesAction;
use App\Auth\Management\Access\SynchronizeAccessCatalogAction;
use App\Auth\Management\ApplicationManagementActor;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuthManagement\PermissionResource;
use App\Http\Resources\AuthManagement\RoleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Exposes deterministic role/permission catalogs without arbitrary policy mutation.
 */
final class AccessCatalogController extends Controller
{
    public function __construct(
        private readonly ApplicationManagementActor $actors,
        private readonly ListRolesAction $listRoles,
        private readonly ListPermissionsAction $listPermissions,
        private readonly SynchronizeAccessCatalogAction $synchronizeCatalog,
    ) {}

    public function roles(Request $request): AnonymousResourceCollection
    {
        return RoleResource::collection(
            $this->listRoles->execute($this->actors->resolve($request)),
        );
    }

    public function permissions(Request $request): AnonymousResourceCollection
    {
        return PermissionResource::collection(
            $this->listPermissions->execute($this->actors->resolve($request)),
        );
    }

    public function synchronize(Request $request): JsonResponse
    {
        $result = $this->synchronizeCatalog->execute(
            $this->actors->resolve($request),
        );

        return response()->json(['data' => [
            'permissionsCreated' => $result->permissionsCreated,
            'permissionsExisting' => $result->permissionsExisting,
            'permissionsDeleted' => $result->permissionsDeleted,
            'rolesCreated' => $result->rolesCreated,
            'rolesSynchronized' => $result->rolesSynchronized,
            'rolesDeleted' => $result->rolesDeleted,
        ]]);
    }
}
