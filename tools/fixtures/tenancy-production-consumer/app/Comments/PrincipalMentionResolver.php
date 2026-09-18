<?php

declare(strict_types=1);

namespace App\Comments;

use App\Models\User;
use Illuminate\Support\Collection;
use Nvl\Auth\Models\TenantMembership;
use Nvl\Comments\Contracts\CommentMentionResourceResolver;
use Nvl\Comments\Data\CommentMentionResourceData;
use Nvl\Comments\ValueObjects\CommentMentionContext;
use Nvl\Tenancy\Contracts\TenantContext;

/** Resolves only principals admitted to the active tenant. */
final readonly class PrincipalMentionResolver implements CommentMentionResourceResolver
{
    public function __construct(private TenantContext $context) {}

    public function resolve(CommentMentionContext $context, array $ids): Collection
    {
        $tenant = $this->context->requireTenant();
        $allowed = TenantMembership::query()
            ->where('tenant_id', $tenant->value)
            ->whereIn('subject_id', $ids)
            ->pluck('subject_id')
            ->filter(static fn (mixed $id): bool => is_string($id))
            ->values();

        return User::query()
            ->whereIn('id', $allowed)
            ->get(['id', 'name'])
            ->map(static fn (User $user): CommentMentionResourceData => new CommentMentionResourceData(
                id: $user->id,
                label: $user->name,
            ));
    }

    public function suggest(CommentMentionContext $context, string $query, int $limit): Collection
    {
        $ids = array_values(TenantMembership::query()
            ->where('tenant_id', $this->context->requireTenant()->value)
            ->limit($limit)
            ->pluck('subject_id')
            ->filter(static fn (mixed $id): bool => is_string($id))
            ->values()
            ->all());

        return $this->resolve($context, $ids)
            ->filter(static fn (CommentMentionResourceData $resource): bool => str_contains(
                mb_strtolower((string) $resource->label),
                mb_strtolower($query),
            ))
            ->take($limit)
            ->values();
    }
}
