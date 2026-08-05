<?php

declare(strict_types=1);

namespace App\Http\Controllers\AuthManagement;

use App\Auth\Management\ApplicationManagementActor;
use App\Auth\Management\Users\CreateUserAction;
use App\Auth\Management\Users\DeleteUserAction;
use App\Auth\Management\Users\FindUserAction;
use App\Auth\Management\Users\ListUsersAction;
use App\Auth\Management\Users\SynchronizeUserAccessAction;
use App\Auth\Management\Users\UpdateUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\AuthManagement\CreateUserRequest;
use App\Http\Requests\AuthManagement\SynchronizeUserAccessRequest;
use App\Http\Requests\AuthManagement\UpdateUserRequest;
use App\Http\Resources\AuthManagement\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Adapts host-owned user management HTTP operations to focused actions.
 */
final class UserManagementController extends Controller
{
    public function __construct(
        private readonly ApplicationManagementActor $actors,
        private readonly ListUsersAction $listUsers,
        private readonly FindUserAction $findUser,
        private readonly CreateUserAction $createUser,
        private readonly UpdateUserAction $updateUser,
        private readonly DeleteUserAction $deleteUser,
        private readonly SynchronizeUserAccessAction $synchronizeAccess,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return UserResource::collection($this->listUsers->execute(
            $this->actors->resolve($request),
            $request->integer('perPage', 25),
        ));
    }

    public function store(CreateUserRequest $request): UserResource
    {
        return new UserResource($this->createUser->execute(
            actor: $this->actors->resolve($request),
            name: $request->name(),
            email: $request->email(),
            password: $request->password(),
            roles: $request->roles(),
            permissions: $request->permissions(),
        ));
    }

    public function show(Request $request, User $user): UserResource
    {
        return new UserResource($this->findUser->execute(
            $this->actors->resolve($request),
            $user,
        ));
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        return new UserResource($this->updateUser->execute(
            actor: $this->actors->resolve($request),
            user: $user,
            name: $request->name(),
            email: $request->email(),
            password: $request->password(),
        ));
    }

    public function destroy(Request $request, User $user): Response
    {
        $this->deleteUser->execute($this->actors->resolve($request), $user);

        return response()->noContent();
    }

    public function access(
        SynchronizeUserAccessRequest $request,
        User $user,
    ): UserResource {
        return new UserResource($this->synchronizeAccess->execute(
            actor: $this->actors->resolve($request),
            user: $user,
            roles: $request->roles(),
            permissions: $request->permissions(),
        ));
    }
}
