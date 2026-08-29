<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Authorization\AuthConsumerAccess;
use App\Mail\QueuedAuthConsumerMail;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use LogicException;
use Nvl\Activity\Facades\ActivityLog as ActivityLogFacade;
use Nvl\Activity\Services\ActivityReadService;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Auth\Actions\Rbac\BootstrapRbacAction;
use Nvl\Auth\Actions\Rbac\CheckRoleNameAvailabilityAction;
use Nvl\Auth\Actions\Rbac\ListPermissionCatalogAction;
use Nvl\Auth\Actions\Rbac\ListPermissionGroupsAction;
use Nvl\Auth\Actions\Rbac\ListPermissionOptionsAction;
use Nvl\Auth\Actions\Rbac\ListRoleCatalogAction;
use Nvl\Auth\Actions\Rbac\ListRoleOptionsAction;
use Nvl\Auth\Actions\Rbac\ResolvePermissionIdentifiersAction;
use Nvl\Auth\Actions\Rbac\ResolveRoleIdentifiersAction;
use Nvl\Auth\Actions\Rbac\ShowRoleAnalyticsAction;
use Nvl\Auth\Actions\Rbac\SuggestPermissionsAction;
use Nvl\Auth\Actions\Rbac\SuggestRolesAction;
use Nvl\Auth\Actions\Users\CreateUserAction;
use Nvl\Auth\Actions\Users\SyncUserRolesAction;
use Nvl\Auth\Data\Display\PermissionOptionData;
use Nvl\Auth\Data\Display\RoleOptionData;
use Nvl\Auth\Data\Mutations\StoreUserData;
use Nvl\Auth\Data\Mutations\SyncUserRolesData;
use Nvl\Auth\Data\Queries\PermissionIndexQueryData;
use Nvl\Auth\Data\Queries\RoleIndexQueryData;
use Nvl\Auth\Exceptions\AuthException;
use Nvl\Auth\ValueObjects\SystemMutationContext;
use Nvl\MailNotifications\Actions\GetMailNotificationStatisticsAction;
use Nvl\MailNotifications\Actions\ListMailNotificationsAction;
use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\ValueObjects\MailNotificationReadQuery;
use Nvl\MailNotifications\ValueObjects\PreparedMessage;
use Nvl\MailNotifications\ValueObjects\ProviderAcceptance;
use Nvl\MailNotifications\ValueObjects\ProviderMessageId;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\TrackingContext;
use Nvl\Settings\Actions\GetSettingAction;
use Nvl\Settings\Actions\ResetSettingAction;
use Nvl\Settings\Actions\SetSettingAction;
use Nvl\Settings\Contracts\SettingsAuthorization;
use Nvl\Settings\Data\SettingMutationData;
use Nvl\Settings\Data\SettingSubjectReferenceData;
use Nvl\Settings\Enums\SettingAbility;
use Nvl\Settings\Events\SettingChanged;
use RuntimeException;

/** Executes the production fixture's cross-package golden Auth workflow. */
final readonly class AuthConsumerProbe
{
    public function __construct(
        private BootstrapRbacAction $bootstrapRbac,
        private CreateUserAction $createUser,
        private SyncUserRolesAction $syncUserRoles,
        private ListRoleOptionsAction $listRoleOptions,
        private SuggestRolesAction $suggestRoles,
        private ListPermissionOptionsAction $listPermissionOptions,
        private SuggestPermissionsAction $suggestPermissions,
        private ListPermissionGroupsAction $listPermissionGroups,
        private ListRoleCatalogAction $listRoleCatalog,
        private ListPermissionCatalogAction $listPermissionCatalog,
        private CheckRoleNameAvailabilityAction $checkRoleNameAvailability,
        private ResolveRoleIdentifiersAction $resolveRoleIdentifiers,
        private ResolvePermissionIdentifiersAction $resolvePermissionIdentifiers,
        private ShowRoleAnalyticsAction $showRoleAnalytics,
        private GetSettingAction $getSetting,
        private SetSettingAction $setSetting,
        private ResetSettingAction $resetSetting,
        private SettingsAuthorization $settingsAuthorization,
        private ActivityReadService $activityReads,
        private TrackingLifecycle $tracking,
        private ListMailNotificationsAction $listMailNotifications,
        private GetMailNotificationStatisticsAction $mailStatistics,
    ) {}

    /**
     * Run every public boundary and return a transport-safe smoke summary.
     *
     * @return array<string, int|string|bool>
     */
    public function run(): array
    {
        $actor = $this->bootstrapActor();
        $principal = $this->createManagedPrincipal($actor);
        $rbac = $this->exerciseRbacReads($actor);
        $activityCount = $this->exerciseSettingActivity($actor);
        $mail = $this->exerciseMailReads($actor, $principal);
        $this->assertDeniedActor($principal);

        return [
            'actor_id' => $this->userIdentifier($actor),
            'principal_id' => $this->userIdentifier($principal),
            'roles' => $rbac['roles'],
            'permissions' => $rbac['permissions'],
            'activity' => $activityCount,
            'mail_total' => $mail['total'],
            'mail_failed' => $mail['failed'],
            'denial_proven' => true,
        ];
    }

    /** @return array{mail_total: int, mail_accepted: int, queued_mail: bool} */
    public function verifyQueuedMail(): array
    {
        $actor = User::query()
            ->where('email', 'administrator@auth-consumer.test')
            ->first();

        if (! $actor instanceof User) {
            throw new LogicException('The proof administrator is missing.');
        }

        Auth::login($actor);

        $queued = $this->listMailNotifications->execute(
            $actor,
            new MailNotificationReadQuery(
                messageCategory: 'auth.consumer.queued',
                acceptedOnly: true,
                perPage: 10,
            ),
        );
        $statistics = $this->mailStatistics->execute(
            $actor,
            new MailNotificationReadQuery(perPage: 10),
        );
        $this->ensure(
            $queued->total === 1
                && $statistics->total === 3
                && $statistics->accepted === 2
                && $statistics->failed === 1,
            'The queued tracked Mailable did not complete through the database worker.',
        );

        return [
            'mail_total' => $statistics->total,
            'mail_accepted' => $statistics->accepted,
            'queued_mail' => true,
        ];
    }

    private function bootstrapActor(): User
    {
        $actor = User::forceCreate([
            'name' => 'Proof Administrator',
            'email' => 'administrator@auth-consumer.test',
            'email_verified_at' => now(),
            'password' => null,
            'is_active' => true,
            'locale' => 'en',
            'timezone' => 'UTC',
            'profile' => [],
            'preferences' => [],
        ]);
        $context = new SystemMutationContext(
            reason: 'auth-production-consumer-bootstrap',
            correlationId: 'auth-production-consumer-bootstrap-v1',
            metadata: ['fixture' => 'CR-19'],
        );
        $result = $this->bootstrapRbac->execute($context);

        $this->ensure($result->rolesSynchronized > 0, 'RBAC templates were not synchronized.');
        $assigned = $this->syncUserRoles->execute(
            $context,
            $actor,
            new SyncUserRolesData(['auth-consumer-administrator']),
        );

        if (! $assigned instanceof User
            || ! $assigned->hasPermissionTo(AuthConsumerAccess::PERMISSION)) {
            throw new LogicException(
                'The proof administrator did not receive the host permission.',
            );
        }

        $actor = $assigned;
        Auth::login($actor);

        return $actor;
    }

    private function createManagedPrincipal(User $actor): User
    {
        $principal = $this->createUser->execute($actor, new StoreUserData(
            name: 'Denied Consumer Principal',
            email: 'denied@auth-consumer.test',
            password: null,
            active: true,
            locale: 'en',
            timezone: 'UTC',
            emailVerified: true,
        ));

        if (! $principal instanceof User) {
            throw new LogicException(
                'Auth did not create the configured principal class.',
            );
        }

        return $principal;
    }

    /** @return array{roles: int, permissions: int} */
    private function exerciseRbacReads(User $actor): array
    {
        $roles = $this->listRoleOptions->execute($actor, limit: 50);
        $role = $roles->firstWhere('name', 'auth-consumer-administrator');
        $permissions = $this->listPermissionOptions->execute($actor, limit: 100);
        $permission = $permissions->firstWhere('name', AuthConsumerAccess::PERMISSION);

        if (! $role instanceof RoleOptionData) {
            throw new LogicException('The consumer role option is missing.');
        }

        if (! $permission instanceof PermissionOptionData) {
            throw new LogicException('The consumer permission option is missing.');
        }
        $this->ensure(
            $this->suggestRoles->execute($actor, 'auth-consumer')->isNotEmpty(),
            'Role suggestions did not find the consumer role.',
        );
        $this->ensure(
            $this->suggestPermissions->execute($actor, 'auth-consumer')->isNotEmpty(),
            'Permission suggestions did not find the consumer permission.',
        );
        $this->ensure(
            $this->listPermissionGroups->execute($actor)->isNotEmpty(),
            'Permission groups are empty.',
        );
        $this->ensure(
            $this->listRoleCatalog->execute(
                $actor,
                new RoleIndexQueryData(perPage: 25, includeAssignments: true),
            )->total() >= 1,
            'The role catalog is empty.',
        );
        $this->ensure(
            $this->listPermissionCatalog->execute(
                $actor,
                new PermissionIndexQueryData(perPage: 25, includeAssignments: true),
            )->total() >= 1,
            'The permission catalog is empty.',
        );
        $this->ensure(
            $this->checkRoleNameAvailability->execute(
                $actor,
                'available-consumer-role',
            )->available,
            'A new role name was reported as unavailable.',
        );
        $this->ensure(
            $this->resolveRoleIdentifiers->execute($actor, [$role->name])->count() === 1,
            'Role identifier resolution failed.',
        );
        $this->ensure(
            $this->resolvePermissionIdentifiers->execute(
                $actor,
                [$permission->name],
            )->count() === 1,
            'Permission identifier resolution failed.',
        );
        $analytics = $this->showRoleAnalytics->execute($actor, $role->id);
        $this->ensure(
            $analytics->activeUsers === 1 && $analytics->permissions >= 1,
            'Role analytics did not include the active administrator.',
        );

        return [
            'roles' => $roles->count(),
            'permissions' => $permissions->count(),
        ];
    }

    private function exerciseSettingActivity(User $actor): int
    {
        $subject = null;
        Event::listen(
            SettingChanged::class,
            static function (SettingChanged $event) use (&$subject): void {
                $subject ??= $event->subject;
            },
        );
        $this->settingsAuthorization->authorize(SettingAbility::Set, 'consumer.enabled');
        $current = $this->getSetting->execute('consumer.enabled');
        $updated = $this->setSetting->execute(new SettingMutationData(
            key: 'consumer.enabled',
            value: true,
            expectedRevision: $current->revision,
        ));
        if (! $subject instanceof SettingSubjectReferenceData) {
            throw new LogicException(
                'Settings did not emit its stable subject reference.',
            );
        }
        ActivityLogFacade::recordForSubjectReference(
            new ActivitySubjectReference($subject->type, $subject->id),
            'setting.changed',
            'The proof setting was enabled.',
            ['setting_key' => $updated->key],
            actor: $actor,
        );
        $this->settingsAuthorization->authorize(SettingAbility::Reset, 'consumer.enabled');
        $reset = $this->resetSetting->execute('consumer.enabled', $updated->revision);
        $this->ensure(
            ! $reset->hasOverride && $reset->value === false,
            'The proof setting did not reset to its definition.',
        );

        $activities = $this->activityReads->forSubjectKey(
            $subject->type,
            $subject->id,
            10,
        );
        $this->ensure($activities->count() === 1, 'Setting activity was not recorded.');

        return $activities->count();
    }

    /** @return array{total: int, failed: int} */
    private function exerciseMailReads(User $actor, User $principal): array
    {
        $context = TrackingContext::forCategory('auth.consumer')
            ->forNotifiable($principal)
            ->withCorrelation(['workflow_id' => 'auth-proof']);
        $this->ensure(
            $context->correlation === ['workflow_id' => 'auth-proof'],
            'The tracking context did not preserve safe correlation.',
        );
        $accepted = $this->tracking->begin($this->message(
            $context,
            'Accepted proof message',
        ));
        $this->tracking->accepted(
            $accepted,
            new ProviderAcceptance(new ProviderMessageId('array', 'accepted-proof')),
        );
        $failed = $this->tracking->begin($this->message(
            $context,
            'Failed proof message',
        ));
        $this->tracking->failed(
            $failed,
            new RuntimeException('Synthetic proof transport failure.'),
        );

        $failedPage = $this->listMailNotifications->execute(
            $actor,
            new MailNotificationReadQuery(failedOnly: true, perPage: 10),
        );
        $statistics = $this->mailStatistics->execute(
            $actor,
            new MailNotificationReadQuery(perPage: 10),
        );
        $item = $failedPage->items[0] ?? null;

        $this->ensure(
            $failedPage->total === 1 && $statistics->total === 2,
            'Mail history did not preserve one accepted and one failed attempt.',
        );
        $this->ensure(
            $statistics->accepted === 1 && $statistics->failed === 1,
            'Mail statistics did not report the expected lifecycle counts.',
        );
        $this->ensure(
            $statistics->mailers[0]->key === 'array'
                && $statistics->mailers[0]->count === 2
                && $statistics->categories[0]->key === 'auth.consumer',
            'Mail dimension aggregates are incomplete.',
        );
        $this->ensure(
            $item !== null
                && ! array_key_exists('metadata', $item->toArray())
                && ! array_key_exists('correlation', $item->toArray()),
            'The mail projection exposed private metadata or correlation payloads.',
        );
        Mail::to('queued@auth-consumer.test')->queue(
            (new QueuedAuthConsumerMail)
                ->forNotifiable($principal)
                ->withTrackingMetadata(['dispatch' => 'database-queue']),
        );

        return ['total' => $statistics->total, 'failed' => $statistics->failed];
    }

    private function message(TrackingContext $context, string $subject): PreparedMessage
    {
        return new PreparedMessage(
            correlationId: Str::uuid()->toString(),
            mailer: 'array',
            context: $context,
            from: new Recipient('sender@auth-consumer.test', 'Auth Consumer'),
            to: [new Recipient('recipient@auth-consumer.test', 'Recipient')],
            subject: $subject,
        );
    }

    private function assertDeniedActor(User $actor): void
    {
        $authDenied = false;
        $settingsDenied = false;
        $mailDenied = false;

        try {
            $this->listRoleOptions->execute($actor);
        } catch (AuthException) {
            $authDenied = true;
        }

        Auth::login($actor);

        try {
            $this->settingsAuthorization->authorize(
                SettingAbility::List,
                'consumer.enabled',
            );
        } catch (AuthorizationException) {
            $settingsDenied = true;
        }

        try {
            $this->listMailNotifications->execute(
                $actor,
                new MailNotificationReadQuery(perPage: 10),
            );
        } catch (AuthorizationException) {
            $mailDenied = true;
        }

        $this->ensure(
            $authDenied && $settingsDenied && $mailDenied,
            'A denied actor reached privileged management data.',
        );
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new LogicException($message);
        }
    }

    private function userIdentifier(User $user): string
    {
        $identifier = $user->getKey();

        if (! is_string($identifier) || $identifier === '') {
            throw new LogicException('The Auth consumer user has no stable UUID.');
        }

        return $identifier;
    }
}
