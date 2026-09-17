<?php

declare(strict_types=1);

use App\Comments\PageCommentTargetResolver;
use App\Comments\PrincipalMentionResolver;
use Nvl\Pages\Models\Page;

return [
    'tenancy' => ['target_types' => ['page' => Page::class]],
    'targets' => ['page' => PageCommentTargetResolver::class],
    'moderation' => ['new_status' => 'approved', 'edited_status' => 'approved'],
    'mentions' => [
        'enabled' => true,
        'resources' => ['principal' => ['resolver' => PrincipalMentionResolver::class]],
    ],
    'mutation_lock' => ['allow_local_store' => true],
];
