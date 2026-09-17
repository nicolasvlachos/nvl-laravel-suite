<?php

declare(strict_types=1);

namespace Nvl\Workbench\Comments;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Nvl\Auth\Definitions\Tables\AuthTables;
use Nvl\Comments\Contracts\CommentMentionTenantProjection;
use Nvl\Comments\ValueObjects\CommentMentionContext;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Limits global principals to active membership in the current tenant. */
final readonly class AuthTenantMentionProjection implements CommentMentionTenantProjection
{
    public function scope(Builder $query, CommentMentionContext $context, TenantId $tenant): void
    {
        $model = $query->getModel();
        $subjectKey = $model->qualifyColumn($model->getKeyName());
        $subjectType = $model->getMorphClass();

        $query->whereExists(static function (QueryBuilder $membership) use (
            $subjectKey,
            $subjectType,
            $tenant,
        ): void {
            $membership->selectRaw('1')
                ->from(AuthTables::TenantMemberships)
                ->whereColumn(AuthTables::TenantMemberships.'.subject_id', $subjectKey)
                ->where(AuthTables::TenantMemberships.'.subject_type', $subjectType)
                ->where(AuthTables::TenantMemberships.'.tenant_id', $tenant->value)
                ->where(AuthTables::TenantMemberships.'.status', 'active');
        });
    }
}
