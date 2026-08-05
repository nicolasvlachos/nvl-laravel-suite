<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\ApiTokens\ApiTokenApiProbe;
use App\Auth\ApiTokens\ApiTokenApiProbeResult;
use App\Auth\Clients\AuthClientApiProbe;
use App\Auth\Clients\AuthClientApiProbeResult;
use App\Auth\Credentials\CredentialAdapterProbe;
use App\Auth\Credentials\CredentialAdapterProbeResult;
use App\Auth\Flows\AuthenticationApiProbe;
use App\Auth\Flows\AuthenticationApiProbeResult;
use App\Auth\Invitations\InvitationWorkflowProbe;
use App\Auth\Invitations\InvitationWorkflowProbeResult;
use App\Auth\Management\Access\ListPermissionsAction;
use App\Auth\Management\Access\ListRolesAction;
use App\Auth\Management\ManagementApiProbe;
use App\Auth\Management\ManagementApiProbeResult;
use App\Auth\Management\Users\CreateUserAction;
use App\Auth\Management\Users\FindUserAction;
use App\Auth\Management\Users\SynchronizeUserAccessAction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;
use Nvl\Auth\Actions\Authorization\SynchronizePermissionCatalogAction;
use Nvl\Auth\Actions\Principals\EnsurePrincipalProjectionAction;
use Nvl\Auth\Contracts\ManagementAccess;
use Nvl\Auth\Contracts\PasswordVerifier;
use Nvl\Auth\Contracts\PrincipalResolver;
use Nvl\Auth\Data\Principals\EnsurePrincipalData;
use Nvl\Auth\Definitions\Tables\AuthTables;
use Nvl\Auth\Results\PasswordVerificationResult;
use Nvl\Auth\ValueObjects\PrincipalLookup;
use Nvl\Auth\ValueObjects\SecretValue;
use ReflectionClass;

/**
 * Exercises the consumer-owned identity, RBAC, management, and package seams.
 */
final class AuthConsumerSmokeCommand extends Command
{
    protected $signature = 'auth-consumer:smoke {--format=text : text or json}';

    protected $description = 'Exercise the representative NVL Auth consumer integration';

    /**
     * Create the complete consumer integration smoke command.
     */
    public function __construct(
        private readonly Hasher $hasher,
        private readonly SynchronizePermissionCatalogAction $synchronizeCatalog,
        private readonly EnsurePrincipalProjectionAction $ensurePrincipal,
        private readonly PrincipalResolver $principals,
        private readonly PasswordVerifier $passwords,
        private readonly CredentialAdapterProbe $credentialAdapters,
        private readonly ManagementAccess $management,
        private readonly CreateUserAction $createUser,
        private readonly FindUserAction $findUser,
        private readonly SynchronizeUserAccessAction $synchronizeUserAccess,
        private readonly ListRolesAction $listRoles,
        private readonly ListPermissionsAction $listPermissions,
        private readonly ManagementApiProbe $managementApi,
        private readonly AuthClientApiProbe $authClientApi,
        private readonly AuthenticationApiProbe $authenticationApi,
        private readonly ApiTokenApiProbe $apiTokenApi,
        private readonly InvitationWorkflowProbe $invitationWorkflow,
        private readonly Router $router,
    ) {
        parent::__construct();
    }

    /**
     * Run one operationally repeatable consumer integration smoke pass.
     */
    public function handle(): int
    {
        $format = $this->option('format');

        if (! is_string($format) || ! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }

        $this->synchronizeCatalog->execute(
            prunePermissions: false,
            pruneRoles: false,
        );
        $administrator = $this->administrator();
        $member = $this->member($administrator);
        $resolved = $this->principals->resolve(new PrincipalLookup(
            type: 'email',
            value: $member->email,
            clientKey: 'consumer-smoke',
        ));
        $verified = $resolved === null
            ? null
            : $this->passwords->verify(
                $resolved,
                new SecretValue('ConsumerPassphrase!123'),
            );
        $managementApi = $this->managementApi->probe($administrator);
        $authClientApi = $this->authClientApi->probe();
        $credentialAdapters = $this->credentialAdapters->probe(
            $member,
            'ConsumerPassphrase!123',
        );
        $invitationWorkflow = $this->invitationWorkflow->probe($administrator);
        $authenticationApi = $this->authenticationApi->probe(
            $member,
            'ConsumerPassphrase!123',
            $authClientApi,
        );
        $authClientApi = $this->authClientApi->cleanup($authClientApi);
        $apiTokenApi = $this->apiTokenApi->probe(
            $member,
            $authenticationApi,
        );
        $report = $this->report(
            $administrator,
            $member,
            $verified,
            $managementApi,
            $authClientApi,
            $authenticationApi,
            $apiTokenApi,
            $invitationWorkflow,
            $credentialAdapters,
        );

        if ($format === 'json') {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            ));
        } else {
            foreach ($report['groups'] as $group => $state) {
                $this->line(sprintf(
                    '%s: %s',
                    $group,
                    $state['ready'] ? 'ready' : 'failed',
                ));
            }
        }

        return $report['healthy'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Create or recover the bootstrap management actor.
     */
    private function administrator(): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'administrator@auth-consumer.test'],
            [
                'name' => 'Auth Administrator',
                'password' => $this->hasher->make('AdministratorPassphrase!123'),
                'email_verified_at' => now(),
            ],
        );
        $this->ensurePrincipal->execute(new EnsurePrincipalData(
            subjectType: $user->getMorphClass(),
            subjectId: (string) $user->getKey(),
        ));
        $user->syncRoles(['auth-administrator']);

        return $user->refresh();
    }

    /**
     * Exercise user creation and assignment through the same actions as the API.
     */
    private function member(User $administrator): User
    {
        $member = User::query()
            ->where('email', 'member@auth-consumer.test')
            ->first();

        if (! $member instanceof User) {
            $member = $this->createUser->execute(
                actor: $administrator,
                name: 'Auth Consumer Member',
                email: 'member@auth-consumer.test',
                password: 'ConsumerPassphrase!123',
                roles: ['member'],
                permissions: [],
            );
        }

        return $this->synchronizeUserAccess->execute(
            actor: $administrator,
            user: $member,
            roles: ['member'],
            permissions: [],
        );
    }

    /**
     * Build grouped evidence for every consumer-owned integration family.
     *
     * @return array{healthy: bool, groups: array<string, array<string, mixed>>}
     */
    private function report(
        User $administrator,
        User $member,
        ?PasswordVerificationResult $verified,
        ManagementApiProbeResult $managementApi,
        AuthClientApiProbeResult $authClientApi,
        AuthenticationApiProbeResult $authenticationApi,
        ApiTokenApiProbeResult $apiTokenApi,
        InvitationWorkflowProbeResult $invitationWorkflow,
        CredentialAdapterProbeResult $credentialAdapters,
    ): array {
        $user = $this->findUser->execute($administrator, $member);
        $roles = $this->listRoles->execute($administrator);
        $permissions = $this->listPermissions->execute($administrator);
        $this->management->principals($administrator)->limit(1)->get();
        $this->management->invitations($administrator)->limit(1)->get();
        $this->management->recoveryCases($administrator)->limit(1)->get();
        $this->management->securityEvents($administrator)->limit(1)->get();
        $packageRoutes = $this->routeCount('nvl.auth.');
        $consumerRoutes = $this->routeCount('consumer.auth.management.');
        $authTables = array_values((new ReflectionClass(AuthTables::class))->getConstants());
        $installedTables = count(array_filter(
            $authTables,
            static fn (mixed $table): bool => is_string($table) && Schema::hasTable($table),
        ));
        $groups = [
            'identity' => [
                'ready' => $user->authPrincipal !== null
                    && $user->getFillable() === ['name', 'email', 'password']
                    && $user->getHidden() === ['password', 'remember_token'],
                'users' => User::query()->count(),
                'principalId' => $user->authPrincipal?->id,
            ],
            'credentials' => [
                'ready' => $verified?->verified === true
                    && $credentialAdapters->healthy(),
                'passwordVerifier' => $this->passwords::class,
                'passwordUpdater' => $credentialAdapters->updated,
                'updateRetryIdempotent' => $credentialAdapters->retryIdempotent,
                'updateCheckpointUnique' => $credentialAdapters->checkpointUnique,
            ],
            'authentication_pipeline' => [
                'ready' => $authenticationApi->healthy(),
                'flowId' => $authenticationApi->flowId,
                'statuses' => array_filter(
                    $authenticationApi->statuses,
                    static fn (string $operation): bool => $operation !== 'session_grants.exchange',
                    ARRAY_FILTER_USE_KEY,
                ),
                'oneTimeSecretsProtected' => $authenticationApi->oneTimeSecretsProtected,
            ],
            'registered_clients' => [
                'ready' => $authClientApi->healthy(),
                'clientId' => $authClientApi->clientId,
                'oneTimeMaterialProtected' => $authClientApi->oneTimeMaterialProtected,
                'statuses' => $authClientApi->statuses,
            ],
            'sessions' => [
                'ready' => $authenticationApi->healthy(),
                'sessionId' => $authenticationApi->sessionId,
                'driver' => $authenticationApi->sessionDriver,
                'exchangeStatus' => $authenticationApi->statuses['session_grants.exchange'] ?? null,
            ],
            'api_tokens' => [
                'ready' => $apiTokenApi->healthy()
                    && $apiTokenApi->sessionId === $authenticationApi->sessionId,
                'tokenId' => $apiTokenApi->tokenId,
                'sessionId' => $apiTokenApi->sessionId,
                'oneTimeMaterialProtected' => $apiTokenApi->oneTimeMaterialProtected,
                'managedBearerAccepted' => $apiTokenApi->managedBearerAccepted,
                'wrongAbilityDenied' => $apiTokenApi->wrongAbilityDenied,
                'unmanagedBearerRejected' => $apiTokenApi->unmanagedBearerRejected,
                'rotatedCredentialRejected' => $apiTokenApi->rotatedCredentialRejected,
                'singlyRevokedBearerRejected' => $apiTokenApi->singlyRevokedBearerRejected,
                'revokedBearerRejected' => $apiTokenApi->revokedBearerRejected,
                'bulkRevokedCount' => $apiTokenApi->bulkRevokedCount,
                'statuses' => $apiTokenApi->statuses,
            ],
            'invitations' => [
                'ready' => $invitationWorkflow->healthy(),
                'invitationId' => $invitationWorkflow->invitationId,
                'acceptanceId' => $invitationWorkflow->acceptanceId,
                'principalId' => $invitationWorkflow->principalId,
                'deliveryScheduled' => $invitationWorkflow->deliveryScheduled,
                'principalProvisioned' => $invitationWorkflow->principalProvisioned,
                'purposeApplied' => $invitationWorkflow->purposeApplied,
                'retryIdempotent' => $invitationWorkflow->retryIdempotent,
            ],
            'authorization' => [
                'ready' => $administrator->hasRole('auth-administrator')
                    && $member->hasRole('member'),
                'roles' => $roles->count(),
                'permissions' => $permissions->count(),
            ],
            'management_api' => [
                'ready' => $consumerRoutes === 9 && $managementApi->healthy(),
                'routes' => $consumerRoutes,
                'routesExercised' => count($managementApi->statuses),
                'authorizationProtected' => $managementApi->authorizationProtected,
                'passwordChangeInvalidatedTrust' => $managementApi->passwordChangeInvalidatedTrust,
                'statuses' => $managementApi->statuses,
            ],
            'package_http' => [
                'ready' => $packageRoutes === 89,
                'routes' => $packageRoutes,
            ],
            'persistence' => [
                'ready' => count($authTables) === 34 && $installedTables === 34,
                'installedAuthTables' => $installedTables,
                'expectedAuthTables' => count($authTables),
            ],
        ];

        return [
            'healthy' => ! in_array(false, array_map(
                static fn (array $group): bool => $group['ready'] === true,
                $groups,
            ), true),
            'groups' => $groups,
        ];
    }

    /**
     * Count one stable route-name family.
     */
    private function routeCount(string $prefix): int
    {
        $count = 0;

        foreach ($this->router->getRoutes() as $route) {
            $name = $route->getName();

            if (is_string($name) && str_starts_with($name, $prefix)) {
                $count++;
            }
        }

        return $count;
    }
}
