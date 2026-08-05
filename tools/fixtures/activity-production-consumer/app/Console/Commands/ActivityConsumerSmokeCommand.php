<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Middleware\AuthenticateActivityConsumer;
use App\Models\ActivityArticle;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use JsonException;
use Nvl\Activity\Data\Display\ActivityItem;
use Nvl\Activity\Enums\ActivityImportance;
use Nvl\Activity\Enums\ActivityVisibility;
use Nvl\Activity\Facades\ActivityLog as ActivityRecorder;
use Nvl\Activity\Jobs\PurgeActivityLogsJob;
use Nvl\Activity\Models\ActivityLog;
use RuntimeException;
use Throwable;

/**
 * Exercises Activity through the same model, API, and queue boundaries used by consumers.
 */
final class ActivityConsumerSmokeCommand extends Command
{
    /** @var string */
    protected $signature = 'activity-consumer:smoke {--format=text : Output text or json}';

    /** @var string */
    protected $description = 'Exercise Activity capture, timelines, APIs, and purge queueing.';

    /**
     * Execute the complete production consumer rehearsal.
     */
    public function handle(HttpKernel $kernel): int
    {
        try {
            $result = $this->exercise($kernel);
        } catch (Throwable $exception) {
            return $this->renderFailure($exception);
        }

        if ($this->option('format') === 'json') {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Activity consumer smoke passed.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     ready: true,
     *     storage: string,
     *     connection: string|null,
     *     customStorage: bool,
     *     activityRows: int,
     *     timelineRows: int,
     *     queuedJobs: int,
     *     endpoints: list<string>
     * }
     */
    private function exercise(HttpKernel $kernel): array
    {
        $this->ensure(
            config('activity.routes.middleware') === ['api']
                && config('activity.routes.management_middleware') === [AuthenticateActivityConsumer::class]
                && config('activity.causer_suggestions.search_attributes') === ['name']
                && config('activity.retention.allowed_purge_options') === [90]
                && config('activity.retention.lock_seconds') === 3600
                && config('activity.capture.ignored_attributes') === ['updated_at'],
            'Activity configuration did not preserve map defaults and replace consumer lists atomically.',
        );
        $this->ensure(
            config('queue.default') === 'database'
                && config('queue.connections.database.driver') === 'database'
                && config('queue.connections.database.retry_after')
                    === PurgeActivityLogsJob::TIMEOUT_SECONDS + 60,
            'The Activity purge queue visibility does not safely exceed the job timeout.',
        );

        $storage = (new ActivityLog)->getTable();
        $connection = (new ActivityLog)->getConnectionName();
        $customStorage = config('activity.migrations.enabled') === false;

        $this->ensure(
            Schema::connection($connection)->hasTable($storage),
            "Activity storage [{$storage}] is missing.",
        );
        $this->ensure(
            $customStorage === ($storage === 'activity_consumer_activity_log'),
            'Activity storage mode and table do not agree.',
        );
        $this->ensure(
            $customStorage === ($connection === 'activity_consumer'),
            'Activity storage mode and connection do not agree.',
        );

        if ($customStorage) {
            $this->ensure(
                ! Schema::hasTable(ActivityLog::DEFAULT_TABLE),
                'The canonical Activity table must not be created in custom-storage mode.',
            );
        } else {
            $this->ensure(
                ! Schema::hasTable('activity_consumer_activity_log'),
                'The custom Activity table must not be created in canonical-storage mode.',
            );
        }

        $this->resetConsumerState();

        $user = User::query()->create([
            'name' => 'Activity Consumer',
            'email' => 'activity-consumer@example.test',
            'password' => Hash::make('activity-consumer-password'),
        ]);
        Auth::setUser($user);

        $article = ActivityArticle::query()->create([
            'title' => 'Production Activity',
            'status' => 'draft',
        ]);
        $article->update(['status' => 'published']);

        $capturedBeforeUnchangedUpdate = ActivityLog::query()->count();
        $article->update(['status' => 'published']);
        $this->ensure(
            ActivityLog::query()->count() === $capturedBeforeUnchangedUpdate,
            'An unchanged model update produced a noisy Activity row.',
        );

        $recorded = ActivityRecorder::record(
            subject: $article,
            event: 'article.published',
            description: 'Article published',
            context: ['channel' => 'production rehearsal'],
            attributes: ['status' => 'published'],
            old: ['status' => 'draft'],
            actor: $user,
            visibility: ActivityVisibility::Timeline,
            importance: ActivityImportance::Important,
        );

        $this->ensure($recorded !== null, 'The canonical recorder returned no activity row.');

        ActivityRecorder::record(
            subject: $article,
            event: 'article.internal_reviewed',
            description: 'Article reviewed internally',
            actor: $user,
            visibility: ActivityVisibility::Hidden,
        );

        $events = ActivityLog::query()
            ->where('subject_type', $article->getMorphClass())
            ->where('subject_id', $this->modelIdentifier($article))
            ->pluck('event')
            ->filter(static fn (mixed $event): bool => is_string($event))
            ->values()
            ->all();

        $expectedEvents = [
            'article.internal_reviewed',
            'article.published',
            'created',
            'updated',
        ];
        sort($events);
        sort($expectedEvents);
        $this->ensure($events === $expectedEvents, 'Activity capture produced an unexpected event multiset.');

        $completeTimeline = $article->buildActivityTimeline();
        $timeline = $article->buildActivityTimeline(2);
        $timelineEvents = array_map(
            static fn (ActivityItem $item): string => $item->event,
            $completeTimeline,
        );
        $expectedTimelineEvents = ['article.published', 'created', 'updated'];
        sort($timelineEvents);
        sort($expectedTimelineEvents);
        $this->ensure(
            $timelineEvents === $expectedTimelineEvents,
            'The complete merged timeline did not contain the exact visible event set.',
        );
        $this->ensure(count($timeline) === 2, 'The finite merged timeline did not enforce its limit.');

        $publicationItem = null;

        foreach ($completeTimeline as $item) {
            if ($item->event === 'article.published') {
                $publicationItem = $item;
            }
        }

        $this->ensure(
            $publicationItem instanceof ActivityItem
                && $publicationItem->subjectLabel === 'Production Activity'
                && $publicationItem->headline === 'Activity Consumer published this article on Production Rehearsal.',
            'The publication event did not use the consumer mapping headline semantics.',
        );

        $identifier = $this->modelIdentifier($user);
        $subjectType = urlencode(ActivityArticle::class);
        $articleIdentifier = urlencode($this->modelIdentifier($article));
        Auth::forgetUser();
        $unauthorized = $this->dispatchJson(
            $kernel,
            'GET',
            '/api/v1/activities?perPage=1',
            null,
        );
        $index = $this->dispatchJson($kernel, 'GET', '/api/v1/activities?perPage=10', $identifier);
        $subjectTimeline = $this->dispatchJson(
            $kernel,
            'GET',
            "/api/v1/activities/timeline?subjectType={$subjectType}&subjectId={$articleIdentifier}&limit=10",
            $identifier,
        );
        $suggestions = $this->dispatchJson(
            $kernel,
            'GET',
            '/api/v1/activities/causers/suggestions?q=Activity&limit=10',
            $identifier,
        );

        $jobsBefore = (int) DB::table('jobs')->count();
        $purge = $this->dispatchJson(
            $kernel,
            'POST',
            '/api/v1/activities/purge',
            $identifier,
            ['days' => 90],
        );
        $systemPurge = $this->dispatchJson(
            $kernel,
            'POST',
            '/api/v1/activities/purge-system',
            $identifier,
            ['days' => 90],
        );
        $queuedJobs = (int) DB::table('jobs')->count() - $jobsBefore;

        $this->ensure($unauthorized['status'] === 401, 'Activity management middleware allowed an anonymous request.');
        $this->ensure($index['status'] === 200, 'The Activity index endpoint failed.');
        $indexTotal = data_get($index['payload'], 'data.activities.meta.total');
        $this->ensure($indexTotal === 4, 'The Activity index did not report the exact captured row count.');
        $indexItems = $this->payloadObjectList($index['payload'], 'data.activities.items');
        $indexEvents = [];
        $indexPublication = null;

        foreach ($indexItems as $item) {
            $event = $item['event'] ?? null;

            if (! is_string($event)) {
                throw new RuntimeException('The Activity API index contains an invalid event value.');
            }

            $indexEvents[] = $event;

            if ($event === 'article.published') {
                $indexPublication = $item;
            }
        }

        sort($indexEvents);
        $this->ensure(
            $indexEvents === $expectedEvents,
            'The Activity API index did not contain the exact captured event set.',
        );
        $this->ensure(
            is_array($indexPublication)
                && ($indexPublication['subjectLabel'] ?? null) === 'Production Activity'
                && ($indexPublication['headline'] ?? null)
                    === 'Activity Consumer published this article on Production Rehearsal.',
            'The Activity API index lost consumer mapping semantics.',
        );
        $this->ensure($subjectTimeline['status'] === 200, 'The Activity timeline endpoint failed.');
        $apiTimeline = $this->payloadObjectList($subjectTimeline['payload'], 'data.activity');
        $apiTimelineEvents = [];
        $apiPublication = null;

        foreach ($apiTimeline as $item) {
            $event = $item['event'] ?? null;

            if (! is_string($event)) {
                throw new RuntimeException('The Activity API timeline contains an invalid event value.');
            }

            $apiTimelineEvents[] = $event;

            if ($event === 'article.published') {
                $apiPublication = $item;
            }
        }

        sort($apiTimelineEvents);
        $this->ensure(
            $apiTimelineEvents === $expectedTimelineEvents,
            'The Activity API timeline did not contain the exact visible event set.',
        );
        $this->ensure(
            is_array($apiPublication)
                && ($apiPublication['subjectLabel'] ?? null) === 'Production Activity'
                && ($apiPublication['headline'] ?? null)
                    === 'Activity Consumer published this article on Production Rehearsal.',
            'The Activity API timeline lost consumer mapping semantics.',
        );
        $this->ensure($suggestions['status'] === 200, 'The causer-suggestion endpoint failed.');
        $this->ensure(
            $this->payloadListContains($suggestions['payload'], 'data', 'id', $identifier),
            'The causer-suggestion endpoint omitted the consumer user.',
        );
        $this->ensure(
            $purge['status'] === 200 && data_get($purge['payload'], 'code') === 'purge_queued',
            'The general purge endpoint did not return its stable queue contract.',
        );
        $this->ensure(
            $systemPurge['status'] === 200
                && data_get($systemPurge['payload'], 'code') === 'purge_system_queued',
            'The system purge endpoint did not return its stable queue contract.',
        );
        $this->ensure($queuedJobs === 2, 'The purge endpoints did not persist exactly two queue jobs.');
        $this->assertQueuedPurgeJobs();
        $activityIdentifiersBeforeWorker = $this->activityIdentifiers();
        $this->ensure(
            count($activityIdentifiersBeforeWorker) === 4,
            'The Activity worker rehearsal did not start with the exact fresh row set.',
        );
        $this->ensure(
            Artisan::call('queue:work', [
                'connection' => 'database',
                '--queue' => 'maintenance',
                '--stop-when-empty' => true,
                '--tries' => 1,
                '--timeout' => 30,
            ]) === self::SUCCESS,
            'The database queue worker failed while executing Activity purge jobs.',
        );
        $this->ensure(DB::table('jobs')->count() === 0, 'The Activity queue worker left purge jobs pending.');
        $this->ensure(DB::table('failed_jobs')->count() === 0, 'An Activity purge job failed in the worker.');
        $this->ensure(
            $this->activityIdentifiers() === $activityIdentifiersBeforeWorker,
            'The 90-day purge worker deleted fresh Activity rows.',
        );

        $articleKey = $this->modelIdentifier($article);
        $article->delete();
        $this->ensure(
            ActivityLog::query()
                ->where('subject_type', $article->getMorphClass())
                ->where('subject_id', $articleKey)
                ->where('event', 'deleted')
                ->count() === 1,
            'Deleting the consumer model did not produce exactly one Activity row.',
        );

        return [
            'ready' => true,
            'storage' => $storage,
            'connection' => $connection,
            'customStorage' => $customStorage,
            'activityRows' => ActivityLog::query()->count(),
            'timelineRows' => count($completeTimeline),
            'queuedJobs' => $queuedJobs,
            'endpoints' => [
                'index',
                'timeline',
                'causer-suggestions',
                'purge',
                'purge-system',
            ],
        ];
    }

    /**
     * Remove rows produced by an earlier idempotent smoke run.
     */
    private function resetConsumerState(): void
    {
        if (Schema::hasTable('jobs')) {
            DB::table('jobs')->delete();
        }

        if (Schema::hasTable('failed_jobs')) {
            DB::table('failed_jobs')->delete();
        }

        ActivityLog::query()->delete();
        ActivityArticle::query()->delete();
        User::query()
            ->where('email', 'activity-consumer@example.test')
            ->delete();
    }

    /**
     * Verify both serialized purge commands and their queue routing.
     */
    private function assertQueuedPurgeJobs(): void
    {
        $jobs = [];

        foreach (DB::table('jobs')->orderBy('id')->get(['queue', 'payload']) as $row) {
            $queue = $row->queue ?? null;
            $encodedPayload = $row->payload ?? null;

            $this->ensure($queue === 'maintenance', 'An Activity purge job used the wrong queue.');

            if (! is_string($encodedPayload)) {
                throw new RuntimeException('An Activity queue row contained an invalid payload.');
            }

            $payload = json_decode($encodedPayload, true, 512, JSON_THROW_ON_ERROR);
            $serialized = data_get($payload, 'data.command');
            $job = is_string($serialized)
                ? unserialize($serialized, ['allowed_classes' => [PurgeActivityLogsJob::class]])
                : null;

            if (! $job instanceof PurgeActivityLogsJob) {
                throw new RuntimeException('The database queue payload did not contain an Activity purge job.');
            }

            $jobs[] = [$job->days, $job->systemOnly];
        }

        sort($jobs);
        $this->ensure(
            $jobs === [[90, false], [90, true]],
            'The queued Activity purge payloads did not preserve both requested scopes.',
        );
    }

    /**
     * Return deterministic identifiers for the current Activity storage rows.
     *
     * @return list<string>
     */
    private function activityIdentifiers(): array
    {
        $identifiers = [];

        foreach (ActivityLog::query()->orderBy('id')->pluck('id') as $identifier) {
            if (! is_string($identifier)) {
                throw new RuntimeException('Activity storage returned a non-string UUID.');
            }

            $identifiers[] = $identifier;
        }

        return $identifiers;
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array{status: int, payload: array<string, mixed>}
     *
     * @throws JsonException
     */
    private function dispatchJson(
        HttpKernel $kernel,
        string $method,
        string $uri,
        ?string $userIdentifier,
        ?array $body = null,
    ): array {
        $server = [
            'HTTP_ACCEPT' => 'application/json',
        ];
        $content = null;

        Auth::forgetUser();

        if ($userIdentifier !== null) {
            $server['HTTP_X_ACTIVITY_CONSUMER_USER'] = $userIdentifier;
        }

        if ($body !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
            $content = json_encode($body, JSON_THROW_ON_ERROR);
        }

        $request = Request::create($uri, $method, [], [], [], $server, $content);
        $response = $kernel->handle($request);

        try {
            $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            $kernel->terminate($request, $response);
        }

        if (! is_array($payload)) {
            throw new RuntimeException("Activity endpoint [{$method} {$uri}] returned a non-object payload.");
        }

        $objectPayload = [];

        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                throw new RuntimeException("Activity endpoint [{$method} {$uri}] returned a non-object payload.");
            }

            $objectPayload[$key] = $value;
        }

        return [
            'status' => $response->getStatusCode(),
            'payload' => $objectPayload,
        ];
    }

    /**
     * Determine whether a response list contains one scalar field value.
     *
     * @param  array<string, mixed>  $payload
     */
    private function payloadListContains(
        array $payload,
        string $path,
        string $key,
        string $expected,
    ): bool {
        foreach ($this->payloadObjectList($payload, $path) as $item) {
            $candidate = $item[$key] ?? null;

            if (is_string($candidate) && $candidate === $expected) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read and validate a list of JSON objects from one response path.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function payloadObjectList(array $payload, string $path): array
    {
        $items = data_get($payload, $path);

        if (! is_array($items) || ! array_is_list($items)) {
            throw new RuntimeException("Activity response path [{$path}] is not a list.");
        }

        $objects = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new RuntimeException("Activity response path [{$path}] contains a non-object item.");
            }

            $object = [];

            foreach ($item as $key => $value) {
                if (! is_string($key)) {
                    throw new RuntimeException("Activity response path [{$path}] contains a non-object item.");
                }

                $object[$key] = $value;
            }

            $objects[] = $object;
        }

        return $objects;
    }

    /**
     * Resolve one scalar Eloquent key for HTTP and morph storage.
     */
    private function modelIdentifier(Model $model): string
    {
        $identifier = $model->getKey();

        if (! is_string($identifier) && ! is_int($identifier)) {
            throw new RuntimeException('The Activity consumer model has an unsupported identifier.');
        }

        return (string) $identifier;
    }

    /**
     * Fail the smoke with a precise production-contract message.
     */
    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    /**
     * Render a stable failure payload for CI and human callers.
     */
    private function renderFailure(Throwable $exception): int
    {
        if ($this->option('format') === 'json') {
            $this->line(json_encode([
                'ready' => false,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->error($exception->getMessage());
        }

        return self::FAILURE;
    }
}
