<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\ApiTokens\ApplicationApiTokenAbilityProvider;
use App\Auth\ApiTokens\ApplicationApiTokenEligibility;
use App\Auth\Authorization\ApplicationPermissionCatalog;
use App\Auth\Authorization\ApplicationRoleTemplates;
use App\Auth\Clients\ApplicationAuthClientManagementAccess;
use App\Auth\Credentials\ApplicationPasswordUpdater;
use App\Auth\Credentials\ApplicationPasswordVerifier;
use App\Auth\Http\SyntheticHttpProbe;
use App\Auth\Identity\ApplicationPrincipalProvisioner;
use App\Auth\Identity\ApplicationPrincipalResolver;
use App\Auth\Invitations\ApplicationInvitationPurpose;
use App\Auth\Management\ApplicationManagementAccess;
use App\Auth\Social\EmulatedSocialIdentityAcquirer;
use App\Console\Commands\AuthConsumerMaintenanceCommand;
use App\Console\Commands\AuthConsumerSmokeCommand;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Nvl\Auth\Adapters\ApiTokens\SanctumApiTokenDriver;
use Nvl\Auth\Adapters\LaravelNotifications\LaravelNotificationMessageDispatcher;
use Nvl\Auth\Adapters\Sessions\LaravelGuardSessionDriver;
use Nvl\Auth\Adapters\WebAuthn\WebauthnPasskeyCeremony;
use Nvl\Auth\Contracts\ApiTokenAbilityProvider;
use Nvl\Auth\Contracts\ApiTokenDriver;
use Nvl\Auth\Contracts\ApiTokenEligibility;
use Nvl\Auth\Contracts\AuthClientManagementAccess;
use Nvl\Auth\Contracts\ManagementAccess;
use Nvl\Auth\Contracts\PasskeyCeremony;
use Nvl\Auth\Contracts\PasswordUpdater;
use Nvl\Auth\Contracts\PasswordVerifier;
use Nvl\Auth\Contracts\PrincipalProvisioner;
use Nvl\Auth\Contracts\PrincipalResolver;
use Nvl\Auth\Contracts\SessionDriver;
use Nvl\Auth\Contracts\SocialIdentityAcquirer;
use RuntimeException;

/**
 * Configures a complete consumer-owned identity and authorization integration.
 */
final class AuthProductionServiceProvider extends ServiceProvider
{
    /**
     * Every feature handle owned by the package manifest.
     *
     * @var list<string>
     */
    private const array FEATURE_HANDLES = [
        'authentication',
        'password',
        'magic_links',
        'security_codes',
        'contacts',
        'invitations',
        'totp',
        'passkeys',
        'recovery_codes',
        'account_recovery',
        'social_identities',
        'devices',
        'cross_device',
        'sessions',
        'clients',
        'api_tokens',
        'rbac',
        'security_notifications',
        'principal_management',
        'security_event_management',
    ];

    /**
     * Feature route configuration leaves grouped by owning feature.
     *
     * @var array<string, list<string>>
     */
    private const array FEATURE_ROUTE_SURFACES = [
        'authentication' => ['public', 'account'],
        'password' => ['public'],
        'magic_links' => ['public'],
        'security_codes' => ['public'],
        'contacts' => ['public', 'account', 'recovery'],
        'invitations' => ['public', 'management'],
        'totp' => ['public', 'account', 'recovery'],
        'passkeys' => ['public', 'account', 'recovery'],
        'recovery_codes' => ['public', 'account', 'recovery'],
        'account_recovery' => ['public', 'management'],
        'social_identities' => ['public', 'account', 'recovery'],
        'devices' => ['account'],
        'cross_device' => ['public', 'account', 'recovery'],
        'sessions' => ['public', 'account'],
        'clients' => ['public', 'management'],
        'api_tokens' => ['account'],
        'principal_management' => ['management'],
        'security_event_management' => ['management'],
    ];

    /**
     * Supported production-consumer activation profiles.
     *
     * @var list<string>
     */
    private const array PROFILES = [
        'browser-baseline',
        'selective-feature',
        'all-enabled',
        'ingress-disabled',
    ];

    /**
     * Configure all package capabilities and register narrow host adapters.
     */
    public function register(): void
    {
        $extensionsRegisteredByPackage = [
            ApplicationPermissionCatalog::class => in_array(
                ApplicationPermissionCatalog::class,
                (array) Config::get(
                    'nvl-auth.features.rbac.settings.permission_catalog_providers',
                    [],
                ),
                true,
            ),
            ApplicationRoleTemplates::class => in_array(
                ApplicationRoleTemplates::class,
                (array) Config::get(
                    'nvl-auth.features.rbac.settings.role_template_providers',
                    [],
                ),
                true,
            ),
            ApplicationInvitationPurpose::class => in_array(
                ApplicationInvitationPurpose::class,
                (array) Config::get(
                    'nvl-auth.features.invitations.settings.purpose_handlers',
                    [],
                ),
                true,
            ),
        ];

        $this->configurePackage();

        $this->app->singleton(PrincipalResolver::class, ApplicationPrincipalResolver::class);
        $this->app->singleton(PrincipalProvisioner::class, ApplicationPrincipalProvisioner::class);
        $this->app->singleton(PasswordVerifier::class, ApplicationPasswordVerifier::class);
        $this->app->singleton(PasswordUpdater::class, ApplicationPasswordUpdater::class);
        $this->app->singleton(ManagementAccess::class, ApplicationManagementAccess::class);
        $this->app->singleton(
            AuthClientManagementAccess::class,
            ApplicationAuthClientManagementAccess::class,
        );
        $this->app->scoped(SessionDriver::class, LaravelGuardSessionDriver::class);
        $this->app->singleton(SyntheticHttpProbe::class);
        $this->app->singleton(ApiTokenDriver::class, SanctumApiTokenDriver::class);
        $this->app->singleton(
            ApiTokenAbilityProvider::class,
            ApplicationApiTokenAbilityProvider::class,
        );
        $this->app->singleton(
            ApiTokenEligibility::class,
            ApplicationApiTokenEligibility::class,
        );
        $this->app->singleton(SocialIdentityAcquirer::class, EmulatedSocialIdentityAcquirer::class);
        $this->app->singleton(PasskeyCeremony::class, WebauthnPasskeyCeremony::class);

        if (! $extensionsRegisteredByPackage[ApplicationPermissionCatalog::class]) {
            $this->registerTaggedExtension(
                ApplicationPermissionCatalog::class,
                'nvl-auth.permission-catalogs',
            );
        }

        if (! $extensionsRegisteredByPackage[ApplicationRoleTemplates::class]) {
            $this->registerTaggedExtension(
                ApplicationRoleTemplates::class,
                'nvl-auth.role-templates',
            );
        }

        if (! $extensionsRegisteredByPackage[ApplicationInvitationPurpose::class]) {
            $this->registerTaggedExtension(
                ApplicationInvitationPurpose::class,
                'nvl-auth.invitation-purposes',
            );
        }
    }

    /**
     * Register host authorization, management routes, and consumer operational commands.
     */
    public function boot(): void
    {
        RateLimiter::for('api', static function (Request $request): Limit {
            $identifier = $request->user()?->getAuthIdentifier();
            $key = is_int($identifier) || is_string($identifier)
                ? (string) $identifier
                : ($request->ip() ?? 'unknown');

            return Limit::perMinute(60)->by($key);
        });

        Gate::define(
            'manage-authentication',
            static fn (mixed $actor): bool => method_exists($actor, 'can')
                && $actor->can('auth.management.access'),
        );

        $this->loadRoutesFrom(base_path('routes/auth-management.php'));

        if ($this->app->runningInConsole()) {
            $this->commands([
                AuthConsumerMaintenanceCommand::class,
                AuthConsumerSmokeCommand::class,
            ]);
        }
    }

    /**
     * Apply an intentionally complete, cache-safe package posture.
     */
    private function configurePackage(): void
    {
        $profile = $this->configuredProfile();
        $enabledFeatures = $this->enabledFeatures($profile);

        Config::set('nvl-auth.enabled', $profile !== 'ingress-disabled');

        foreach (self::FEATURE_HANDLES as $feature) {
            Config::set(
                "nvl-auth.features.{$feature}.enabled",
                in_array($feature, $enabledFeatures, true),
            );
            Config::set("nvl-auth.features.{$feature}.mode", 'enabled');
        }

        $this->configureRoutes($profile, $enabledFeatures);
        $this->configureSharedSecurity();
        $this->configureFeatureIntegrations($enabledFeatures);
    }

    /**
     * Resolve and validate the selected cache-safe consumer profile.
     */
    private function configuredProfile(): string
    {
        $profile = Config::get('auth-consumer.profile', 'all-enabled');

        if (! is_string($profile) || ! in_array($profile, self::PROFILES, true)) {
            throw new RuntimeException(sprintf(
                'Unsupported Auth production-consumer profile [%s].',
                is_scalar($profile) ? (string) $profile : get_debug_type($profile),
            ));
        }

        return $profile;
    }

    /**
     * Return the exact enabled feature set for one rehearsal profile.
     *
     * @return list<string>
     */
    private function enabledFeatures(string $profile): array
    {
        return match ($profile) {
            'browser-baseline' => [
                'authentication',
                'password',
                'devices',
                'sessions',
            ],
            'selective-feature' => [
                'authentication',
                'password',
                'magic_links',
                'security_codes',
                'contacts',
                'totp',
                'devices',
                'sessions',
                'security_notifications',
            ],
            'all-enabled', 'ingress-disabled' => self::FEATURE_HANDLES,
            default => throw new RuntimeException(
                "Unsupported Auth production-consumer profile [{$profile}].",
            ),
        };
    }

    /**
     * Configure global and feature-owned HTTP route admission for one profile.
     *
     * @param  list<string>  $enabledFeatures
     */
    private function configureRoutes(string $profile, array $enabledFeatures): void
    {
        $routeSurfaces = match ($profile) {
            'browser-baseline' => [],
            'selective-feature' => ['public', 'account'],
            'all-enabled', 'ingress-disabled' => ['public', 'account', 'management'],
            default => throw new RuntimeException(
                "Unsupported Auth production-consumer profile [{$profile}].",
            ),
        };

        Config::set('nvl-auth.routes.enabled', $routeSurfaces !== []);
        Config::set('nvl-auth.routes.middleware', ['web']);
        Config::set(
            'nvl-auth.routes.public.enabled',
            in_array('public', $routeSurfaces, true),
        );
        Config::set(
            'nvl-auth.routes.account.enabled',
            in_array('account', $routeSurfaces, true),
        );
        Config::set('nvl-auth.routes.account.middleware', [
            'auth',
            'throttle:nvl-auth-account',
        ]);
        Config::set(
            'nvl-auth.routes.management.enabled',
            in_array('management', $routeSurfaces, true),
        );
        Config::set('nvl-auth.routes.management.middleware', [
            'auth',
            'can:manage-authentication',
            'throttle:nvl-auth-management',
        ]);

        foreach (self::FEATURE_ROUTE_SURFACES as $feature => $surfaces) {
            foreach ($surfaces as $surface) {
                Config::set(
                    "nvl-auth.features.{$feature}.routes.{$surface}.enabled",
                    in_array($feature, $enabledFeatures, true)
                        && $this->routeSurfaceEnabled($profile, $surface),
                );
            }
        }
    }

    /**
     * Determine whether one feature-owned route leaf belongs to the profile.
     */
    private function routeSurfaceEnabled(string $profile, string $surface): bool
    {
        if ($surface === 'recovery') {
            return in_array($profile, ['all-enabled', 'ingress-disabled'], true);
        }

        if ($surface === 'management') {
            return in_array($profile, ['all-enabled', 'ingress-disabled'], true);
        }

        return $profile !== 'browser-baseline';
    }

    /**
     * Configure security infrastructure shared by every profile.
     */
    private function configureSharedSecurity(): void
    {
        Config::set('nvl-auth.security.allowed_link_hosts', ['auth-consumer.test']);
        Config::set('nvl-auth.security.allowed_redirect_hosts', [
            'auth-consumer.test',
            'app.auth-consumer.test',
        ]);
        Config::set('nvl-auth.security.hash_keys.active', 'production-ci');
        Config::set('nvl-auth.security.hash_keys.keys', [
            'production-ci' => 'ProductionReadinessAuthKey-32-Bytes-Minimum',
        ]);
    }

    /**
     * Configure only adapters and policies owned by enabled features.
     *
     * @param  list<string>  $enabledFeatures
     */
    private function configureFeatureIntegrations(array $enabledFeatures): void
    {
        if ($this->hasFeature($enabledFeatures, 'passkeys')) {
            Config::set(
                'nvl-auth.features.passkeys.settings.relying_party_id',
                'auth-consumer.test',
            );
            Config::set(
                'nvl-auth.features.passkeys.settings.relying_party_name',
                'Auth Production Consumer',
            );
            Config::set(
                'nvl-auth.features.passkeys.settings.origins',
                ['https://auth-consumer.test'],
            );
            Config::set(
                'nvl-auth.features.passkeys.services.ceremony',
                WebauthnPasskeyCeremony::class,
            );
        }

        if ($this->hasFeature($enabledFeatures, 'account_recovery')) {
            Config::set(
                'nvl-auth.features.account_recovery.settings.trusted_device_evidence_enabled',
                true,
            );
        }

        if ($this->hasFeature($enabledFeatures, 'invitations')) {
            Config::set('nvl-auth.features.invitations.settings.default_purpose', 'member');
            Config::set('nvl-auth.features.invitations.settings.purpose_handlers', [
                ApplicationInvitationPurpose::class,
            ]);
        }

        if ($this->hasFeature($enabledFeatures, 'social_identities')) {
            $this->configureSocialIdentities();
        }

        if ($this->requiresMessageDelivery($enabledFeatures)) {
            Config::set(
                'nvl-auth.messages.delivery.dispatcher',
                LaravelNotificationMessageDispatcher::class,
            );
        }

        if ($this->hasFeature($enabledFeatures, 'authentication')) {
            Config::set(
                'nvl-auth.features.authentication.services.principal_resolver',
                ApplicationPrincipalResolver::class,
            );
            Config::set(
                'nvl-auth.features.authentication.services.principal_provisioner',
                ApplicationPrincipalProvisioner::class,
            );
        }

        if ($this->hasFeature($enabledFeatures, 'password')) {
            Config::set(
                'nvl-auth.features.password.services.verifier',
                ApplicationPasswordVerifier::class,
            );
            Config::set(
                'nvl-auth.features.password.services.updater',
                ApplicationPasswordUpdater::class,
            );
        }

        if ($this->hasFeature($enabledFeatures, 'sessions')) {
            Config::set(
                'nvl-auth.features.sessions.services.driver',
                LaravelGuardSessionDriver::class,
            );
        }

        if ($this->hasFeature($enabledFeatures, 'api_tokens')) {
            Config::set(
                'nvl-auth.features.api_tokens.services.driver',
                SanctumApiTokenDriver::class,
            );
            Config::set(
                'nvl-auth.features.api_tokens.services.ability_provider',
                ApplicationApiTokenAbilityProvider::class,
            );
            Config::set(
                'nvl-auth.features.api_tokens.services.eligibility',
                ApplicationApiTokenEligibility::class,
            );
        }

        if ($this->hasFeature($enabledFeatures, 'clients')) {
            Config::set(
                'nvl-auth.features.clients.services.management_access',
                ApplicationAuthClientManagementAccess::class,
            );
        }

        if ($this->requiresGeneralManagement($enabledFeatures)) {
            Config::set(
                'nvl-auth.services.management_access',
                ApplicationManagementAccess::class,
            );
        }

        if ($this->hasFeature($enabledFeatures, 'rbac')) {
            Config::set('nvl-auth.features.rbac.settings.permission_catalog_providers', [
                ApplicationPermissionCatalog::class,
            ]);
            Config::set('nvl-auth.features.rbac.settings.role_template_providers', [
                ApplicationRoleTemplates::class,
            ]);
        }
    }

    /**
     * Configure the complete emulated social-provider integration.
     */
    private function configureSocialIdentities(): void
    {
        Config::set('nvl-auth.features.social_identities.settings.callback_hosts', [
            'auth-consumer.test',
        ]);
        Config::set('nvl-auth.features.social_identities.settings.providers', [
            'github' => [
                'driver' => 'github',
                'authorization_hosts' => ['oauth.example.test'],
                'scopes' => ['read:user'],
                'parameters' => [],
                'pkce' => true,
                'nonce' => false,
                'nonce_opt_out_reason' => 'The emulated fixture provider is OAuth-only.',
                'profile_fields' => [],
            ],
        ]);
        Config::set(
            'nvl-auth.features.social_identities.services.acquirer',
            EmulatedSocialIdentityAcquirer::class,
        );
    }

    /**
     * Determine whether one feature belongs to the enabled profile set.
     *
     * @param  list<string>  $enabledFeatures
     */
    private function hasFeature(array $enabledFeatures, string $feature): bool
    {
        return in_array($feature, $enabledFeatures, true);
    }

    /**
     * Determine whether the profile has any functional delivery producer.
     *
     * @param  list<string>  $enabledFeatures
     */
    private function requiresMessageDelivery(array $enabledFeatures): bool
    {
        return array_intersect($enabledFeatures, [
            'magic_links',
            'security_codes',
            'invitations',
            'account_recovery',
            'security_notifications',
        ]) !== [];
    }

    /**
     * Determine whether an enabled management feature needs the general host contract.
     *
     * @param  list<string>  $enabledFeatures
     */
    private function requiresGeneralManagement(array $enabledFeatures): bool
    {
        return array_intersect($enabledFeatures, [
            'invitations',
            'account_recovery',
            'principal_management',
            'security_event_management',
        ]) !== [];
    }

    /**
     * Register one extension before the package resolves and freezes its registry.
     *
     * @param  class-string  $extension
     */
    private function registerTaggedExtension(string $extension, string $tag): void
    {
        $this->app->singletonIf($extension);
        $this->app->tag($extension, $tag);
    }
}
