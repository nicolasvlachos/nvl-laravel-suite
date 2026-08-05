<?php

declare(strict_types=1);

use App\Comments\Authorization\ApplicationCommentAuthorization;
use App\Comments\Authors\ApplicationCommentAuthorPresenter;
use App\Comments\Http\CommentsConsumerActorResolver;
use App\Comments\Targets\ArticleCommentTargetResolver;

return [
    'connection' => null,

    'tables' => [
        'comments' => 'comments',
        'comment_reactions' => 'comment_reactions',
        'comment_revisions' => 'comment_revisions',
        'comment_reports' => 'comment_reports',
    ],

    'migrations' => [
        'enabled' => true,
    ],

    'authorization' => [
        'class' => ApplicationCommentAuthorization::class,
    ],

    'query_scope' => [
        'class' => ApplicationCommentAuthorization::class,
    ],

    'actor_resolver' => [
        'class' => CommentsConsumerActorResolver::class,
    ],

    'author_presenter' => [
        'class' => ApplicationCommentAuthorPresenter::class,
    ],

    'targets' => [
        'article' => ArticleCommentTargetResolver::class,
    ],

    'routes' => [
        'public' => [
            'enabled' => true,
            'prefix' => 'api/v1/discussions',
            'name' => 'nvl.comments.public.',
            'middleware' => ['api', 'throttle:comments-consumer-public'],
        ],
        'member' => [
            'enabled' => true,
            'prefix' => 'api/v1/member/discussions',
            'name' => 'nvl.comments.member.',
            'middleware' => [
                'api',
                'auth:comments_consumer',
                'throttle:comments-consumer-member',
            ],
        ],
        'management' => [
            'enabled' => true,
            'prefix' => 'api/v1/comments',
            'name' => 'nvl.comments.management.',
            'middleware' => [
                'api',
                'auth:comments_consumer',
                'throttle:comments-consumer-management',
            ],
        ],
        'attachments' => [
            'enabled' => true,
            'prefix' => 'api/v1/comment-attachments',
            'name' => 'nvl.comments.attachments.',
            'middleware' => ['api', 'throttle:comments-consumer-assets'],
        ],
    ],

    'moderation' => [
        'new_status' => 'pending',
        'edited_status' => 'pending',
        'restored_status' => 'pending',
        'actionable_statuses' => ['pending', 'spam'],
        'allow_author_delete' => true,
        'allow_author_restore' => true,
    ],

    'anonymous' => [
        'enabled' => false,
    ],

    'content' => [
        'maximum_bytes' => 20_000,
        'allowed_formats' => ['plain'],
        'maximum_tags' => 10,
    ],

    'reactions' => [
        'allowed' => ['like', 'helpful'],
    ],

    'attachments' => [
        'enabled' => true,
        'maximum_per_comment' => 3,
        'maximum_file_bytes' => 1024 * 1024,
        'allow_public_media' => false,
        'signed_url_lifetime' => 5,
    ],

    'mutation_lock' => [
        'enabled' => true,
        'store' => 'database',
        'seconds' => 60,
        'wait_seconds' => 10,
        'allow_local_store' => false,
    ],

    'transactions' => [
        'attempts' => 3,
    ],

    'reconciliation' => [
        'chunk_size' => 100,
    ],

    'pagination' => [
        'default' => 20,
        'maximum' => 50,
    ],

    'cache' => [
        'public_max_age' => 60,
    ],
];
