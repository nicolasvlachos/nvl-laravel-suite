<?php

declare(strict_types=1);

namespace App\Comments\Probe;

use App\Comments\Authorization\ApplicationCommentAuthorization;
use App\Comments\Authorization\ApplicationMediaAuthorization;
use App\Comments\Authors\ApplicationCommentAuthorPresenter;
use App\Comments\Http\CommentsConsumerActorResolver;
use App\Models\CommentsArticle;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Hash;
use Nvl\Comments\Contracts\CommentActorResolver;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Contracts\CommentAuthorPresenter;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Models\Comment;
use Nvl\Media\Actions\DeleteMediaAction;
use Nvl\Media\Actions\DetachMediaAction;
use Nvl\Media\Actions\UploadMediaAction;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Enums\MimeType;
use Nvl\Media\Models\Media;
use Nvl\Media\Slots\MediaSlot;
use RuntimeException;

/**
 * Exercises the complete Comments integration through real consumer and HTTP seams.
 */
final readonly class CommentsConsumerProbe
{
    private const string AUTHOR_EMAIL = 'author@comments-consumer.test';

    private const string MEMBER_EMAIL = 'member@comments-consumer.test';

    private const string UPPER_TARGET = 'Article-A';

    private const string LOWER_TARGET = 'article-a';

    private const string ATTACHMENT_CONTENT = "Production comment attachment.\n";

    /**
     * Create the executable probe with every consumer-owned and package boundary explicit.
     */
    public function __construct(
        private Application $application,
        private Router $router,
        private ConsoleKernel $console,
        private Filesystem $files,
        private SyntheticCommentsHttpClient $http,
        private UploadMediaAction $uploadMedia,
        private DetachMediaAction $detachMedia,
        private DeleteMediaAction $deleteMedia,
        private CommentAuthorization $authorization,
        private CommentQueryScope $queryScope,
        private CommentActorResolver $actorResolver,
        private CommentAuthorPresenter $authorPresenter,
        private MediaAuthorization $mediaAuthorization,
    ) {}

    /**
     * Run the full production-consumer scenario and return machine-readable evidence.
     *
     * @return array{
     *     ready: true,
     *     comments: int,
     *     reports: int,
     *     attachments: int,
     *     exactTargets: list<string>,
     *     endpoints: list<string>,
     *     reconciliationHealthy: true
     * }
     */
    public function exercise(): array
    {
        $this->assertDeploymentConfiguration();
        [$author, $member, $moderator, $identityAuditor] = $this->users();
        [$upperTarget, $lowerTarget] = $this->targets();
        $this->resetTargetState([$upperTarget, $lowerTarget]);

        $this->assertTransportGuards($author);
        [$publicCommentId, $lowerCommentId] = $this->createPublicTargets($author);
        $this->assertExactTargetQueues(
            $publicCommentId,
            $lowerCommentId,
            $moderator,
            $identityAuditor,
        );
        $this->approveAndReadPublicComment(
            $publicCommentId,
            $moderator,
            $upperTarget,
            $lowerTarget,
        );
        $this->exerciseMemberWorkflow(
            $publicCommentId,
            $member,
        );
        $this->exerciseReportWorkflow(
            $publicCommentId,
            $member,
            $moderator,
            $identityAuditor,
        );
        $this->exerciseAttachmentWorkflow(
            $publicCommentId,
            $author,
            $upperTarget,
        );
        $this->assertReconciliation();

        return [
            'ready' => true,
            'comments' => Comment::query()->withTrashed()->count(),
            'reports' => 1,
            'attachments' => 1,
            'exactTargets' => [self::UPPER_TARGET, self::LOWER_TARGET],
            'endpoints' => [
                'public.create',
                'public.list',
                'member.create',
                'member.list',
                'member.update',
                'member.reaction',
                'member.report',
                'management.moderate',
                'management.report-queue',
                'management.report-resolve',
                'management.identity-gate',
                'attachments.attach',
                'attachments.list',
                'attachments.signed-delivery',
            ],
            'reconciliationHealthy' => true,
        ];
    }

    /**
     * Verify cached deployment state, consumer contracts, route hardening, and list replacement.
     */
    private function assertDeploymentConfiguration(): void
    {
        $this->ensure(
            $this->application->configurationIsCached(),
            'The Comments consumer did not run with cached configuration.',
        );
        $this->ensure(
            $this->application->routesAreCached(),
            'The Comments consumer did not run with cached routes.',
        );
        $this->ensure(
            $this->authorization instanceof ApplicationCommentAuthorization
                && $this->queryScope instanceof ApplicationCommentAuthorization
                && $this->actorResolver instanceof CommentsConsumerActorResolver
                && $this->authorPresenter instanceof ApplicationCommentAuthorPresenter
                && $this->mediaAuthorization instanceof ApplicationMediaAuthorization,
            'A consumer-owned Comments or Media contract was not bound.',
        );
        $this->ensure(
            config('comments.content.allowed_formats') === ['plain']
                && config('comments.reactions.allowed') === ['like', 'helpful']
                && config('comments.moderation.actionable_statuses') === ['pending', 'spam'],
            'Consumer list configuration retained package defaults instead of replacing them.',
        );
        $this->ensure(
            config('comments.mutation_lock.enabled') === true
                && config('comments.mutation_lock.store') === 'database'
                && config('cache.default') === 'database',
            'The consumer did not configure one shared database lock domain.',
        );

        $expectedMiddleware = [
            'nvl.comments.member.index' => [
                'api',
                'auth:comments_consumer',
                'throttle:comments-consumer-member',
            ],
            'nvl.comments.management.index' => [
                'api',
                'auth:comments_consumer',
                'throttle:comments-consumer-management',
            ],
            'nvl.comments.attachments.asset' => [
                'api',
                'throttle:comments-consumer-assets',
                'signed',
            ],
        ];

        foreach ($expectedMiddleware as $name => $middleware) {
            $route = $this->router->getRoutes()->getByName($name);

            if ($route === null) {
                throw new RuntimeException("Required Comments route [{$name}] is missing.");
            }

            $gathered = $route->gatherMiddleware();

            foreach ($middleware as $entry) {
                $this->ensure(
                    in_array($entry, $gathered, true),
                    "Required middleware [{$entry}] is missing from [{$name}].",
                );
            }
        }
    }

    /**
     * Create or refresh deterministic users for every consumer role.
     *
     * @return array{User, User, User, User}
     */
    private function users(): array
    {
        return [
            $this->user(self::AUTHOR_EMAIL, 'Comments Author'),
            $this->user(self::MEMBER_EMAIL, 'Comments Member'),
            $this->user(ApplicationCommentAuthorization::MODERATOR, 'Comments Moderator'),
            $this->user(
                ApplicationCommentAuthorization::IDENTITY_AUDITOR,
                'Comments Identity Auditor',
            ),
        ];
    }

    /**
     * Create or refresh one deterministic authenticated consumer.
     */
    private function user(string $email, string $name): User
    {
        return User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('comments-consumer-password'),
            ],
        );
    }

    /**
     * Create two targets whose identifiers differ only by case.
     *
     * @return array{CommentsArticle, CommentsArticle}
     */
    private function targets(): array
    {
        return [
            CommentsArticle::query()->updateOrCreate(
                ['id' => self::UPPER_TARGET],
                ['title' => 'Uppercase Production Article'],
            ),
            CommentsArticle::query()->updateOrCreate(
                ['id' => self::LOWER_TARGET],
                ['title' => 'Lowercase Production Article'],
            ),
        ];
    }

    /**
     * Remove state from an earlier idempotent smoke run through package lifecycle boundaries.
     *
     * @param  list<CommentsArticle>  $targets
     */
    private function resetTargetState(array $targets): void
    {
        foreach ($targets as $target) {
            $comments = $target->comments()->withTrashed()->get();

            foreach ($comments as $comment) {
                $associations = $comment->attachmentAssociations()->with('media')->get();

                foreach ($associations as $association) {
                    $media = $association->getRelation('media');

                    if (! $media instanceof Media) {
                        continue;
                    }

                    $this->detachMedia->execute($media, $comment, 'attachments');
                    $this->deleteMedia->execute($media, force: true);
                }

                $comment->forceDelete();
            }
        }
    }

    /**
     * Prove authentication denial and JSON validation without an Accept header.
     */
    private function assertTransportGuards(User $author): void
    {
        $unauthorized = $this->http->json(
            'GET',
            '/api/v1/comments/targets/article/'.self::UPPER_TARGET,
        );
        $this->ensure(
            $unauthorized['status'] === 401,
            'The management route accepted an unauthenticated request.',
        );

        $forbidden = $this->http->json(
            'GET',
            '/api/v1/comments/targets/article/'.self::UPPER_TARGET,
            $author->email,
        );
        $this->ensure(
            $forbidden['status'] === 403,
            'The management route accepted an authenticated non-manager.',
        );

        $validation = $this->http->json(
            'POST',
            '/api/v1/member/discussions/targets/article/'.self::UPPER_TARGET,
            $author->email,
            [],
            acceptJson: false,
        );
        $this->ensure(
            $validation['status'] === 422
                && str_starts_with((string) $validation['contentType'], 'application/json')
                && is_array(data_get($validation['payload'], 'errors.body')),
            'A Comments API validation failure without Accept did not remain JSON.',
        );
    }

    /**
     * Create comments against case-distinct targets through the public API.
     *
     * @return array{string, string}
     */
    private function createPublicTargets(User $author): array
    {
        $upper = $this->http->json(
            'POST',
            '/api/v1/discussions/targets/article/'.self::UPPER_TARGET,
            $author->email,
            ['body' => 'Public production comment.'],
            ['Idempotency-Key' => '10000000-0000-4000-8000-000000000001'],
        );
        $lower = $this->http->json(
            'POST',
            '/api/v1/discussions/targets/article/'.self::LOWER_TARGET,
            $author->email,
            ['body' => 'Case-distinct pending comment.'],
            ['Idempotency-Key' => '10000000-0000-4000-8000-000000000002'],
        );

        $this->ensure(
            $upper['status'] === 201 && $lower['status'] === 201,
            'Public comment creation failed for one case-distinct target.',
        );

        return [
            $this->payloadString($upper, 'data.id'),
            $this->payloadString($lower, 'data.id'),
        ];
    }

    /**
     * Prove target isolation plus the independent management identity permission.
     */
    private function assertExactTargetQueues(
        string $upperCommentId,
        string $lowerCommentId,
        User $moderator,
        User $identityAuditor,
    ): void {
        $upperModerator = $this->managementQueue(self::UPPER_TARGET, $moderator);
        $upperAuditor = $this->managementQueue(self::UPPER_TARGET, $identityAuditor);
        $lowerModerator = $this->managementQueue(self::LOWER_TARGET, $moderator);
        $upperModeratorItem = $this->onlyObject($upperModerator, 'data');
        $upperAuditorItem = $this->onlyObject($upperAuditor, 'data');
        $lowerModeratorItem = $this->onlyObject($lowerModerator, 'data');

        $this->ensure(
            ($upperModeratorItem['id'] ?? null) === $upperCommentId
                && ($lowerModeratorItem['id'] ?? null) === $lowerCommentId,
            'Case-distinct target queues leaked or crossed comment identities.',
        );
        $this->ensure(
            ! array_key_exists('actorType', $upperModeratorItem)
                && ! array_key_exists('actorId', $upperModeratorItem),
            'Moderation permission alone exposed a stored actor identity.',
        );
        $this->ensure(
            ($upperAuditorItem['actorType'] ?? null)
                === CommentsConsumerActorResolver::ACTOR_TYPE
                && ($upperAuditorItem['actorId'] ?? null) === self::AUTHOR_EMAIL,
            'The separately authorized identity auditor did not receive management identity.',
        );
    }

    /**
     * Return one target's management queue for an authenticated manager.
     *
     * @return array{
     *     status: int,
     *     payload: array<string, mixed>|null,
     *     contentType: string|null,
     *     cacheControl: string|null
     * }
     */
    private function managementQueue(string $targetId, User $actor): array
    {
        $response = $this->http->json(
            'GET',
            "/api/v1/comments/targets/article/{$targetId}",
            $actor->email,
        );
        $this->ensure($response['status'] === 200, 'The management queue request failed.');

        return $response;
    }

    /**
     * Approve the upper target comment and prove viewer-independent public reads.
     */
    private function approveAndReadPublicComment(
        string $commentId,
        User $moderator,
        CommentsArticle $upperTarget,
        CommentsArticle $lowerTarget,
    ): void {
        $moderation = $this->http->json(
            'PUT',
            "/api/v1/comments/{$commentId}/moderation",
            $moderator->email,
            [
                'status' => 'approved',
                'expectedRevision' => 1,
                'reason' => 'Consumer production review.',
            ],
        );
        $this->ensure(
            $moderation['status'] === 200
                && data_get($moderation['payload'], 'data.revision') === 2,
            'Management moderation did not approve the public comment.',
        );

        $upper = $this->http->json(
            'GET',
            '/api/v1/discussions/targets/article/'.$upperTarget->id,
        );
        $lower = $this->http->json(
            'GET',
            '/api/v1/discussions/targets/article/'.$lowerTarget->id,
        );
        $upperItem = $this->onlyObject($upper, 'data');
        $lowerItems = $this->objectList($lower, 'data');

        $this->ensure(
            $upper['status'] === 200
                && ($upperItem['id'] ?? null) === $commentId
                && data_get($upperItem, 'author.displayName') === 'Comments Author'
                && ! array_key_exists('status', $upperItem)
                && $this->hasCacheControlDirectives(
                    $upper['cacheControl'],
                    ['public', 'max-age=60', 's-maxage=60'],
                ),
            'The public projection or shared-cache contract is not consumer-safe: '
                .json_encode($upper, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        $this->ensure(
            $lower['status'] === 200 && $lowerItems === [],
            'The pending case-distinct target comment leaked into public reads.',
        );
    }

    /**
     * Exercise member creation, scoped listing, optimistic update, and reaction state.
     */
    private function exerciseMemberWorkflow(string $publicCommentId, User $member): string
    {
        $created = $this->http->json(
            'POST',
            '/api/v1/member/discussions/targets/article/'.self::UPPER_TARGET,
            $member->email,
            [
                'body' => 'Private member draft.',
                'visibility' => 'private',
            ],
        );
        $this->ensure(
            $created['status'] === 201
                && data_get($created['payload'], 'data.author.displayName') === 'Comments Member'
                && data_get($created['payload'], 'data.isAuthor') === true,
            'Member comment creation lost actor presentation or ownership abilities.',
        );
        $memberCommentId = $this->payloadString($created, 'data.id');
        $index = $this->http->json(
            'GET',
            '/api/v1/member/discussions/targets/article/'.self::UPPER_TARGET,
            $member->email,
        );
        $this->ensure(
            $index['status'] === 200
                && count($this->objectList($index, 'data')) === 2,
            'The member query scope did not combine public and own private comments.',
        );

        $updated = $this->http->json(
            'PATCH',
            "/api/v1/member/discussions/comments/{$memberCommentId}",
            $member->email,
            [
                'body' => 'Updated private member draft.',
                'expectedRevision' => 1,
            ],
        );
        $this->ensure(
            $updated['status'] === 200
                && data_get($updated['payload'], 'data.body')
                    === 'Updated private member draft.'
                && data_get($updated['payload'], 'data.revision') === 2,
            'The optimistic member update failed.',
        );

        $reaction = $this->http->json(
            'PUT',
            "/api/v1/member/discussions/comments/{$publicCommentId}/reaction",
            $member->email,
            ['type' => 'helpful', 'active' => true],
        );
        $helpful = $this->findObject(
            $this->objectList($reaction, 'data.reactions'),
            'type',
            'helpful',
        );
        $this->ensure(
            $reaction['status'] === 200
                && ($helpful['count'] ?? null) === 1
                && ($helpful['viewerActive'] ?? null) === true,
            'The member reaction did not return deterministic viewer state.',
        );

        return $memberCommentId;
    }

    /**
     * Exercise reporting, target-scoped management queues, identity gating, and review.
     */
    private function exerciseReportWorkflow(
        string $commentId,
        User $member,
        User $moderator,
        User $identityAuditor,
    ): string {
        $reported = $this->http->json(
            'POST',
            "/api/v1/member/discussions/comments/{$commentId}/reports",
            $member->email,
            [
                'reason' => 'consumer-review',
                'details' => 'Representative management review evidence.',
            ],
        );
        $this->ensure(
            $reported['status'] === 202
                && data_get($reported['payload'], 'data.reported') === true,
            'The member report endpoint failed.',
        );

        $moderatorQueue = $this->reportQueue($moderator);
        $auditorQueue = $this->reportQueue($identityAuditor);
        $moderatorItem = $this->onlyObject($moderatorQueue, 'data');
        $auditorItem = $this->onlyObject($auditorQueue, 'data');
        $moderatorReport = $this->nestedObject($moderatorItem, 'report');
        $auditorReport = $this->nestedObject($auditorItem, 'report');
        $moderatorComment = $this->nestedObject($moderatorItem, 'comment');
        $auditorComment = $this->nestedObject($auditorItem, 'comment');

        $this->ensure(
            ! array_key_exists('reporterType', $moderatorReport)
                && ! array_key_exists('reporterId', $moderatorReport)
                && ! array_key_exists('actorType', $moderatorComment),
            'A moderator without identity permission received reporter or author identity.',
        );
        $this->ensure(
            ($auditorReport['reporterType'] ?? null)
                === CommentsConsumerActorResolver::ACTOR_TYPE
                && ($auditorReport['reporterId'] ?? null) === self::MEMBER_EMAIL
                && ($auditorComment['actorId'] ?? null) === self::AUTHOR_EMAIL,
            'The identity auditor did not receive authorized report and author evidence.',
        );

        $reportId = $this->arrayString($moderatorReport, 'id');
        $resolved = $this->http->json(
            'PUT',
            "/api/v1/comments/reports/{$reportId}",
            $moderator->email,
            [
                'status' => 'resolved',
                'expectedRevision' => 2,
                'resolution' => 'Reviewed by the production consumer.',
            ],
        );
        $this->ensure(
            $resolved['status'] === 200
                && data_get($resolved['payload'], 'data.status') === 'resolved'
                && ! array_key_exists(
                    'reporterType',
                    $this->nestedObject($resolved['payload'] ?? [], 'data'),
                ),
            'The management report review contract failed or leaked identity.',
        );
        $this->ensure(
            $this->objectList($this->reportQueue($moderator), 'data') === [],
            'The resolved report remained in the actionable target queue.',
        );

        return $reportId;
    }

    /**
     * Return the exact target's actionable report queue for one manager.
     *
     * @return array{
     *     status: int,
     *     payload: array<string, mixed>|null,
     *     contentType: string|null,
     *     cacheControl: string|null
     * }
     */
    private function reportQueue(User $manager): array
    {
        $response = $this->http->json(
            'GET',
            '/api/v1/comments/targets/article/'.self::UPPER_TARGET.'/reports',
            $manager->email,
        );
        $this->ensure($response['status'] === 200, 'The target report queue request failed.');

        return $response;
    }

    /**
     * Upload, associate, list, and deliver a private Media attachment through signed routes.
     */
    private function exerciseAttachmentWorkflow(
        string $commentId,
        User $author,
        CommentsArticle $target,
    ): string {
        $media = $this->uploadAttachment($target, $author);
        $attached = $this->http->json(
            'POST',
            "/api/v1/member/discussions/comments/{$commentId}/attachments",
            $author->email,
            ['mediaId' => $media->id],
        );
        $this->ensure($attached['status'] === 201, 'The member attachment request failed.');
        $associationId = $this->payloadString($attached, 'data.associationId');
        $assetUrl = $this->payloadString($attached, 'data.assetUrl');
        $this->ensure(
            str_contains($assetUrl, $associationId)
                && ! str_contains($assetUrl, $media->id),
            'The signed attachment URL exposed Media identity or lost association scope.',
        );

        $memberList = $this->http->json(
            'GET',
            "/api/v1/member/discussions/comments/{$commentId}/attachments",
            $author->email,
        );
        $publicList = $this->http->json(
            'GET',
            "/api/v1/discussions/comments/{$commentId}/attachments",
        );
        $this->ensure(
            ($this->onlyObject($memberList, 'data')['associationId'] ?? null)
                === $associationId
                && ($this->onlyObject($publicList, 'data')['associationId'] ?? null)
                    === $associationId,
            'The consumer Media policy did not project the live attachment by audience.',
        );

        $asset = $this->http->asset($assetUrl);
        $this->ensure(
            $asset['status'] === 200
                && $asset['content'] === self::ATTACHMENT_CONTENT
                && str_starts_with((string) $asset['contentType'], 'text/plain')
                && $this->hasCacheControlDirectives(
                    $asset['cacheControl'],
                    ['private', 'no-store', 'max-age=0'],
                )
                && $asset['contentTypeOptions'] === 'nosniff'
                && is_string($asset['etag']),
            'Signed attachment delivery lost its file or private-response contract.',
        );
        $notModified = $this->http->asset(
            $assetUrl,
            ['If-None-Match' => (string) $asset['etag']],
        );
        $this->ensure(
            $notModified['status'] === 304
                && $this->hasCacheControlDirectives(
                    $notModified['cacheControl'],
                    ['private', 'no-store', 'max-age=0'],
                ),
            'Signed attachment ETag revalidation failed.',
        );

        $publicIndex = $this->http->json(
            'GET',
            '/api/v1/discussions/targets/article/'.self::UPPER_TARGET,
        );
        $publicComment = $this->findObject(
            $this->objectList($publicIndex, 'data'),
            'id',
            $commentId,
        );
        $this->ensure(
            ($publicComment['attachmentCount'] ?? null) === 1,
            'The public batch projection did not count the authorized attachment.',
        );

        return $associationId;
    }

    /**
     * Upload one private text asset through Media's validated ingestion Action.
     */
    private function uploadAttachment(CommentsArticle $target, User $author): Media
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'nvl-comments-consumer-');

        if (! is_string($temporaryPath)) {
            throw new RuntimeException('The Comments consumer could not create an upload fixture.');
        }

        $this->files->put($temporaryPath, self::ATTACHMENT_CONTENT);

        try {
            $slot = (new MediaSlot('comments-consumer-upload'))
                ->useDisk('local')
                ->path('comments-consumer/{model_id}')
                ->privateExclusive()
                ->acceptsMimeTypes([MimeType::Txt])
                ->maxFileSize(1024 * 1024);

            return $this->uploadMedia->execute(
                file: new UploadedFile(
                    $temporaryPath,
                    'production-evidence.txt',
                    MimeType::Txt->value,
                    null,
                    true,
                ),
                disk: 'local',
                model: $target,
                slot: $slot,
                fileName: 'production-evidence.txt',
                isPublic: false,
                skipAutoVariations: true,
                uploadedBy: $author->email,
                uploadedByType: CommentsConsumerActorResolver::ACTOR_TYPE,
            );
        } finally {
            $this->files->delete($temporaryPath);
        }
    }

    /**
     * Run target-scoped reconciliation and require a drift-free machine result.
     */
    private function assertReconciliation(): void
    {
        $status = $this->console->call('nvl:comments:reconcile', [
            '--target' => 'article:'.self::UPPER_TARGET,
            '--strict' => true,
            '--format' => 'json',
        ]);
        $payload = json_decode($this->console->output(), true, 512, JSON_THROW_ON_ERROR);

        $this->ensure(
            $status === 0
                && is_array($payload)
                && ($payload['healthy'] ?? null) === true
                && ($payload['remaining'] ?? null) === 0,
            'Target-scoped Comments reconciliation did not report a healthy consumer state.',
        );
    }

    /**
     * Return the only object in one response list.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function onlyObject(array $response, string $path): array
    {
        $items = $this->objectList($response, $path);
        $this->ensure(count($items) === 1, "Expected exactly one object at [{$path}].");

        return $items[0];
    }

    /**
     * Return a string-keyed nested object.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function nestedObject(array $payload, string $path): array
    {
        $value = data_get($payload, $path);

        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException("Expected an object at [{$path}].");
        }

        $object = [];

        foreach ($value as $key => $entry) {
            if (! is_string($key)) {
                throw new RuntimeException("Expected string keys at [{$path}].");
            }

            $object[$key] = $entry;
        }

        return $object;
    }

    /**
     * Return a list of string-keyed objects from one response path.
     *
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function objectList(array $response, string $path): array
    {
        $value = data_get($response, "payload.{$path}");

        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException("Expected an object list at [{$path}].");
        }

        $objects = [];

        foreach ($value as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new RuntimeException("Expected object entries at [{$path}].");
            }

            $object = [];

            foreach ($item as $key => $entry) {
                if (! is_string($key)) {
                    throw new RuntimeException("Expected string object keys at [{$path}].");
                }

                $object[$key] = $entry;
            }

            $objects[] = $object;
        }

        return $objects;
    }

    /**
     * Find one object by an exact scalar field value.
     *
     * @param  list<array<string, mixed>>  $objects
     * @return array<string, mixed>
     */
    private function findObject(array $objects, string $field, string $value): array
    {
        foreach ($objects as $object) {
            if (($object[$field] ?? null) === $value) {
                return $object;
            }
        }

        throw new RuntimeException("No object with [{$field}] equal to [{$value}] was found.");
    }

    /**
     * Return one required string from an HTTP response payload.
     *
     * @param  array<string, mixed>  $response
     */
    private function payloadString(array $response, string $path): string
    {
        $value = data_get($response, "payload.{$path}");

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Expected a non-empty string at [{$path}].");
        }

        return $value;
    }

    /**
     * Return one required string from an object.
     *
     * @param  array<string, mixed>  $object
     */
    private function arrayString(array $object, string $key): string
    {
        $value = $object[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Expected a non-empty string at [{$key}].");
        }

        return $value;
    }

    /**
     * Require an order-independent set of cache-control directives.
     *
     * @param  list<string>  $expected
     */
    private function hasCacheControlDirectives(?string $value, array $expected): bool
    {
        if ($value === null) {
            return false;
        }

        $actual = array_map(trim(...), explode(',', $value));

        foreach ($expected as $directive) {
            if (! in_array($directive, $actual, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Require one probe invariant and fail with a consumer-facing diagnostic.
     */
    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
