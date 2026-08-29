<?php

declare(strict_types=1);

namespace Nvl\Seo\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Nvl\Data\Data\PaginatedCollection;
use Nvl\Data\Data\PaginationMeta;
use Nvl\Seo\Actions\ArchiveSeoProfileAction;
use Nvl\Seo\Actions\DeleteSeoProfileAction;
use Nvl\Seo\Actions\DuplicateSeoProfileAction;
use Nvl\Seo\Actions\GetSeoProfileAction;
use Nvl\Seo\Actions\ListSeoProfilesAction;
use Nvl\Seo\Actions\PreviewSeoProfileAction;
use Nvl\Seo\Actions\SeoProfileStatusAction;
use Nvl\Seo\Actions\SyncSeoProfileAction;
use Nvl\Seo\Contracts\SeoAuthorization;
use Nvl\Seo\Enums\SeoAbility;
use Nvl\Seo\Http\Requests\ArchiveSeoProfileRequest;
use Nvl\Seo\Http\Requests\DeleteSeoProfileRequest;
use Nvl\Seo\Http\Requests\DuplicateSeoProfileRequest;
use Nvl\Seo\Http\Requests\ListSeoProfilesRequest;
use Nvl\Seo\Http\Requests\PreviewSeoProfileRequest;
use Nvl\Seo\Http\Requests\SeoProfileStatusRequest;
use Nvl\Seo\Http\Requests\StoreSeoProfileRequest;
use Nvl\Seo\Http\Requests\UpdateSeoProfileRequest;
use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Services\SeoOwnerRegistry;
use Nvl\Seo\Services\SeoProfilePresenter;
use Nvl\Seo\Support\SeoAuthorizationContext;
use Nvl\Seo\Support\SeoScope;

/**
 * Optional authorized, headless profile management API.
 */
final class SeoManagementController extends Controller
{
    public function __construct(
        private readonly SeoAuthorization $authorization,
        private readonly SeoOwnerRegistry $owners,
        private readonly SeoProfilePresenter $presenter,
    ) {}

    /**
     * Return a paginated management list of profiles.
     */
    public function index(
        ListSeoProfilesRequest $request,
        ListSeoProfilesAction $action,
    ): JsonResponse {
        $query = $request->profileQuery();
        $profiles = $action->execute($query);
        $items = [];

        foreach ($profiles->items() as $profile) {
            $items[] = $this->stringKeyed(
                $profile->toArray(),
            );
        }
        $collection = new PaginatedCollection(
            items: $items,
            meta: new PaginationMeta(
                currentPage: $profiles->currentPage(),
                lastPage: $profiles->lastPage(),
                perPage: $profiles->perPage(),
                total: $profiles->total(),
            ),
        );

        return response()->json([
            'data' => $collection->toArray(),
        ]);
    }

    /**
     * Return one authorized management profile.
     */
    public function show(string $profile, GetSeoProfileAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute($profile)->toArray(),
        ]);
    }

    /**
     * Create a profile for one authorized registered owner.
     */
    public function store(
        StoreSeoProfileRequest $request,
        SyncSeoProfileAction $action,
    ): JsonResponse {
        $ownerAlias = $request->ownerAlias();
        $owner = $this->owners->resolve($ownerAlias, $request->ownerId());
        $scope = SeoScope::normalize($request->scope());
        $this->authorization->authorize(new SeoAuthorizationContext(
            ability: SeoAbility::Create,
            owner: $owner,
            ownerAlias: $ownerAlias,
            scope: $scope,
        ));
        $profile = $action->execute(
            $owner,
            $request->payload(),
            $scope,
        );

        return response()->json(['data' => $this->presenter->present($profile)->toArray()], 201);
    }

    /**
     * Update one profile with a mandatory optimistic revision token.
     */
    public function update(
        UpdateSeoProfileRequest $request,
        string $profile,
        SyncSeoProfileAction $action,
    ): JsonResponse {
        $profileModel = $this->profileModel($profile);
        $owner = $this->authorizeProfile(SeoAbility::Update, $profileModel);
        $updated = $action->execute($owner, $request->payload(), $profileModel->scope);

        return response()->json(['data' => $this->presenter->present($updated)->toArray()]);
    }

    /**
     * Duplicate one profile to an authorized target owner.
     */
    public function duplicate(
        DuplicateSeoProfileRequest $request,
        string $profile,
        DuplicateSeoProfileAction $action,
    ): JsonResponse {
        $profileModel = $this->profileModel($profile);
        $ownerAlias = $request->ownerAlias();
        $target = $this->owners->resolve($ownerAlias, $request->ownerId());
        $sourceOwner = $profileModel->seoable()->firstOrFail();
        $sourceOwnerAlias = $this->owners->aliasFor($sourceOwner);
        $scope = SeoScope::normalize($request->scope());
        $this->authorization->authorize(new SeoAuthorizationContext(
            ability: SeoAbility::Duplicate,
            profile: $profileModel,
            owner: $sourceOwner,
            ownerAlias: $sourceOwnerAlias,
            targetOwner: $target,
            targetOwnerAlias: $ownerAlias,
            scope: $scope,
        ));
        $duplicate = $action->execute(
            $profile,
            $target,
            $scope,
            $request->copyPaths(),
        );

        return response()->json(['data' => $this->presenter->present($duplicate)->toArray()], 201);
    }

    /**
     * Archive or restore one authorized profile.
     */
    public function archive(
        ArchiveSeoProfileRequest $request,
        string $profile,
        ArchiveSeoProfileAction $action,
    ): JsonResponse {
        $profileModel = $this->profileModel($profile);
        $this->authorizeProfile(SeoAbility::Archive, $profileModel);
        $updated = $action->execute(
            $profile,
            $request->archived(),
            $request->expectedRevision(),
        );

        return response()->json(['data' => $this->presenter->present($updated)->toArray()]);
    }

    /**
     * Delete one authorized profile.
     */
    public function destroy(
        DeleteSeoProfileRequest $request,
        string $profile,
        DeleteSeoProfileAction $action,
    ): JsonResponse {
        $profileModel = $this->profileModel($profile);
        $this->authorizeProfile(SeoAbility::Delete, $profileModel);

        return response()->json([
            'data' => [
                'deleted' => $action->execute(
                    $profile,
                    $request->expectedRevision(),
                ),
            ],
        ]);
    }

    /**
     * Preview resolved metadata for one authorized profile.
     */
    public function preview(
        PreviewSeoProfileRequest $request,
        string $profile,
        PreviewSeoProfileAction $action,
    ): JsonResponse {
        $profileModel = $this->profileModel($profile);
        $this->authorizeProfile(SeoAbility::Preview, $profileModel);

        return response()->json([
            'data' => $action->execute(
                $profileModel,
                $request->locale(),
            )->toArray(),
        ]);
    }

    /**
     * Return aggregate status for one authorized scope.
     */
    public function status(
        SeoProfileStatusRequest $request,
        SeoProfileStatusAction $action,
    ): JsonResponse {
        $scope = $request->scope();
        $this->authorization->authorize(new SeoAuthorizationContext(
            ability: SeoAbility::List,
            scope: $scope === null ? null : SeoScope::normalize($scope),
        ));

        return response()->json([
            'data' => $action->execute($scope)->toArray(),
        ]);
    }

    /**
     * Authorize a profile operation with its registered owner identity.
     */
    private function authorizeProfile(SeoAbility $ability, SeoProfile $profile): Model
    {
        $owner = $profile->seoable()->firstOrFail();
        $this->authorization->authorize(new SeoAuthorizationContext(
            ability: $ability,
            profile: $profile,
            owner: $owner,
            ownerAlias: $this->owners->aliasFor($owner),
            scope: $profile->scope,
        ));

        return $owner;
    }

    /**
     * Load one profile model for mutation and preview authorization contexts.
     */
    private function profileModel(string $profile): SeoProfile
    {
        return SeoProfile::query()
            ->with('translations')
            ->findOrFail($profile);
    }

    /**
     * Normalize one Data payload into its documented string-keyed shape.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    private function stringKeyed(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
