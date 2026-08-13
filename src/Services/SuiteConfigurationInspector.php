<?php

declare(strict_types=1);

namespace Nvl\Suite\Services;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;
use Nvl\Suite\Support\SuiteModuleCatalog;
use Throwable;

/**
 * Builds a secret-free view of the suite's effective runtime configuration.
 *
 * @phpstan-import-type MigrationDefinition from SuiteModuleCatalog
 * @phpstan-import-type AliasReader from SuiteModuleCatalog
 * @phpstan-import-type ScheduleDefinition from SuiteModuleCatalog
 * @phpstan-import-type ProfileDefinition from SuiteModuleCatalog
 *
 * @phpstan-type EffectiveMigration array{mode: string, config: string|null, enabled: bool|null, owner: string}
 * @phpstan-type EffectiveSchedule array{command: string, condition: string|null, enabled: bool|null, registered: bool, required: bool}
 * @phpstan-type EffectiveModule array{
 *     requested: bool,
 *     enabled: bool,
 *     dependency: bool,
 *     provider: class-string,
 *     provider_loaded: bool,
 *     stateful: bool,
 *     migration: EffectiveMigration,
 *     doctor: string|null,
 *     implementations: array<class-string, string>,
 *     registered_aliases: list<string>,
 *     queues: list<string>,
 *     schedules: list<EffectiveSchedule>,
 *     typescript: bool
 * }
 * @phpstan-type ProfileReport array{name: string, description: string, modules: list<string>, matches: bool}
 * @phpstan-type SuiteConfigurationReport array{
 *     profile: ProfileReport|null,
 *     profiles: array<string, ProfileDefinition>,
 *     modules: array<string, EffectiveModule>,
 *     morph_aliases: list<string>
 * }
 */
final readonly class SuiteConfigurationInspector
{
    public function __construct(
        private Application $application,
        private Repository $configuration,
        private Schedule $schedule,
        private SuiteModuleCatalog $catalog,
    ) {}

    /**
     * @return SuiteConfigurationReport
     */
    public function inspect(?string $profile = null): array
    {
        $effectiveModules = $this->catalog->effectiveModules();
        $effectiveLookup = array_fill_keys($effectiveModules, true);
        $definitions = $this->catalog->modules();
        $modules = [];

        foreach ($definitions as $module => $definition) {
            $enabled = isset($effectiveLookup[$module]);
            $requested = $this->catalog->requested($module);

            $modules[$module] = [
                'requested' => $requested,
                'enabled' => $enabled,
                'dependency' => $enabled && ! $requested,
                'provider' => $definition['provider'],
                'provider_loaded' => $this->application->providerIsLoaded($definition['provider']),
                'stateful' => $definition['stateful'],
                'migration' => $this->migration($definition['migration']),
                'doctor' => $definition['doctor'],
                'implementations' => $enabled
                    ? $this->implementations($definition['contracts'])
                    : [],
                'registered_aliases' => $enabled
                    ? $this->aliases($definition['aliases'])
                    : [],
                'queues' => $definition['queues'],
                'schedules' => $this->schedules($definition['schedules']),
                'typescript' => $definition['typescript'],
            ];
        }

        return [
            'profile' => $this->profile($profile, $effectiveModules),
            'profiles' => $this->catalog->profiles(),
            'modules' => $modules,
            'morph_aliases' => $this->morphAliases(),
        ];
    }

    /**
     * @param  MigrationDefinition  $migration
     * @return EffectiveMigration
     */
    private function migration(array $migration): array
    {
        if ($migration['mode'] === 'none') {
            return [...$migration, 'enabled' => null, 'owner' => 'none'];
        }

        if ($migration['mode'] === 'domain-owned') {
            return [...$migration, 'enabled' => null, 'owner' => 'domain'];
        }

        $enabled = $this->configuration->get($migration['config']);

        return [
            ...$migration,
            'enabled' => is_bool($enabled) ? $enabled : null,
            'owner' => match ($enabled) {
                true => 'package',
                false => 'application',
                default => 'invalid',
            },
        ];
    }

    /**
     * @param  list<class-string>  $contracts
     * @return array<class-string, string>
     */
    private function implementations(array $contracts): array
    {
        $implementations = [];

        foreach ($contracts as $contract) {
            try {
                $implementations[$contract] = get_debug_type($this->application->make($contract));
            } catch (Throwable $throwable) {
                $implementations[$contract] = 'unresolvable:'.get_debug_type($throwable);
            }
        }

        return $implementations;
    }

    /**
     * @param  list<AliasReader>  $readers
     * @return list<string>
     */
    private function aliases(array $readers): array
    {
        $aliases = [];

        foreach ($readers as $reader) {
            try {
                $service = $this->application->make($reader['service']);

                if (! is_callable([$service, $reader['method']])) {
                    continue;
                }

                $values = $service->{$reader['method']}();

                if (! is_array($values)) {
                    continue;
                }

                if (array_is_list($values)) {
                    foreach ($values as $value) {
                        if (is_string($value) && $value !== '') {
                            $aliases[] = $value;
                        }
                    }

                    continue;
                }

                foreach (array_keys($values) as $alias) {
                    if (is_string($alias) && $alias !== '') {
                        $aliases[] = $alias;
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        $aliases = array_values(array_unique($aliases));
        sort($aliases);

        return $aliases;
    }

    /**
     * @param  list<ScheduleDefinition>  $schedules
     * @return list<EffectiveSchedule>
     */
    private function schedules(array $schedules): array
    {
        $registeredCommands = array_values(array_filter(array_map(
            static fn ($event): ?string => is_string($event->command) ? $event->command : null,
            $this->schedule->events(),
        )));

        return array_map(function (array $schedule) use ($registeredCommands): array {
            $enabled = $schedule['enabled'] === null
                ? null
                : $this->configuration->get($schedule['enabled']);
            $registered = collect($registeredCommands)->contains(
                static fn (string $registeredCommand): bool => Str::contains(
                    $registeredCommand,
                    $schedule['command'],
                ),
            );

            return [
                'command' => $schedule['command'],
                'condition' => $schedule['enabled'],
                'enabled' => is_bool($enabled) ? $enabled : null,
                'registered' => $registered,
                'required' => $schedule['required_when_enabled'] && $enabled === true,
            ];
        }, $schedules);
    }

    /**
     * @param  list<string>  $effectiveModules
     * @return ProfileReport|null
     */
    private function profile(?string $profile, array $effectiveModules): ?array
    {
        if ($profile === null) {
            return null;
        }

        $profiles = $this->catalog->profiles();
        $profileModules = $this->catalog->profileModules($profile);

        return [
            'name' => $profile,
            'description' => $profiles[$profile]['description'],
            'modules' => $profileModules,
            'matches' => $profileModules === $effectiveModules,
        ];
    }

    /**
     * @return list<string>
     */
    private function morphAliases(): array
    {
        $aliases = array_keys(Relation::morphMap());
        sort($aliases);

        return $aliases;
    }
}
