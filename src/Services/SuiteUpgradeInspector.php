<?php

declare(strict_types=1);

namespace Nvl\Suite\Services;

use Nvl\Suite\Support\SuiteModuleCatalog;

/**
 * Reviews a published suite configuration against the current module catalog.
 *
 * @phpstan-type UpgradeFinding array{
 *     code: string,
 *     severity: 'error'|'warning',
 *     module: string|null,
 *     symbol: string,
 *     message: string,
 *     remediation: string
 * }
 */
final readonly class SuiteUpgradeInspector
{
    public function __construct(private SuiteModuleCatalog $catalog) {}

    /**
     * @param  array<mixed>  $configuration
     * @return list<UpgradeFinding>
     */
    public function inspect(array $configuration): array
    {
        $configuredModules = $configuration['modules'] ?? null;

        if (! is_array($configuredModules)) {
            return [[
                'code' => 'upgrade.modules_invalid',
                'severity' => 'error',
                'module' => null,
                'symbol' => 'modules',
                'message' => 'The published suite configuration has no valid modules map.',
                'remediation' => 'Regenerate config/nvl-suite.php and review every module decision.',
            ]];
        }

        $definitions = $this->catalog->modules();
        $findings = [];

        foreach ($configuredModules as $module => $enabled) {
            if (! is_string($module) || ! isset($definitions[$module])) {
                $findings[] = [
                    'code' => 'upgrade.module_unknown',
                    'severity' => 'error',
                    'module' => is_string($module) ? $module : null,
                    'symbol' => is_string($module) ? $module : 'non-string-module-key',
                    'message' => 'The published configuration contains an unknown suite module.',
                    'remediation' => 'Remove the retired or unsupported module decision after reviewing the upgrade notes.',
                ];

                continue;
            }

            if (! is_bool($enabled)) {
                $findings[] = [
                    'code' => 'upgrade.module_invalid',
                    'severity' => 'error',
                    'module' => $module,
                    'symbol' => 'modules.'.$module,
                    'message' => 'The published module decision is not boolean.',
                    'remediation' => 'Set the module decision explicitly to true or false.',
                ];
            }
        }

        foreach ($definitions as $module => $definition) {
            if (array_key_exists($module, $configuredModules)) {
                continue;
            }

            $findings[] = [
                'code' => 'upgrade.module_missing',
                'severity' => 'error',
                'module' => $module,
                'symbol' => 'modules.'.$module,
                'message' => 'The current suite contains a module with no published consumer decision.',
                'remediation' => 'Choose true or false for the module before adopting this suite version.',
            ];

            if ($definition['migration']['mode'] === 'configurable') {
                $findings[] = [
                    'code' => 'upgrade.migration_ownership_review',
                    'severity' => 'warning',
                    'module' => $module,
                    'symbol' => (string) $definition['migration']['config'],
                    'message' => 'A newly encountered module requires an explicit migration ownership review.',
                    'remediation' => 'Decide whether package or application migrations own this module schema.',
                ];
            }

            foreach ($definition['contracts'] as $contract) {
                $findings[] = [
                    'code' => 'upgrade.required_contract_review',
                    'severity' => 'warning',
                    'module' => $module,
                    'symbol' => $contract,
                    'message' => 'A newly encountered module exposes a host integration contract.',
                    'remediation' => 'Review and bind the contract before enabling the module.',
                ];
            }

            foreach ($definition['schedules'] as $schedule) {
                if (! $schedule['required_when_enabled']) {
                    continue;
                }

                $findings[] = [
                    'code' => 'upgrade.required_schedule_review',
                    'severity' => 'warning',
                    'module' => $module,
                    'symbol' => $schedule['command'],
                    'message' => 'A newly encountered module may require a scheduler entry when enabled.',
                    'remediation' => 'Review the schedule condition and register the command when required.',
                ];
            }
        }

        return $findings;
    }

    /**
     * Return whether findings should fail the requested policy level.
     *
     * @param  list<UpgradeFinding>  $findings
     */
    public function fails(array $findings, bool $strict): bool
    {
        foreach ($findings as $finding) {
            if ($finding['severity'] === 'error') {
                return true;
            }
        }

        return false;
    }
}
