<?php

declare(strict_types=1);

namespace Nvl\Suite\Services\ConsumerAudit;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use InvalidArgumentException;
use Nvl\Suite\Services\SuiteConfigurationInspector;
use Nvl\Suite\Services\SuiteSkillManager;
use Nvl\Suite\Support\ConsumerAuditFinding;
use Nvl\Suite\Support\SuiteModuleCatalog;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Audits effective runtime wiring that source scanning cannot prove.
 *
 * @phpstan-import-type SuiteConfigurationReport from SuiteConfigurationInspector
 */
final readonly class SuiteRuntimeConsumerScanner
{
    public function __construct(
        private SuiteConfigurationInspector $inspector,
        private Router $router,
        private Kernel $console,
        private SuiteSkillManager $skills,
        private SuiteModuleCatalog $catalog,
        private Repository $configuration,
    ) {}

    /**
     * @return list<ConsumerAuditFinding>
     */
    public function scan(): array
    {
        $report = $this->inspector->inspect();
        $findings = [
            ...$this->configurationFindings($report),
            ...$this->routeFindings($report),
            ...$this->generatedTypeFindings($report),
            ...$this->skillFindings(),
        ];

        return $this->sortedUnique($findings);
    }

    /**
     * @param  SuiteConfigurationReport  $report
     * @return list<ConsumerAuditFinding>
     */
    private function configurationFindings(array $report): array
    {
        $findings = [];

        foreach ($report['modules'] as $package => $module) {
            if (! $module['enabled']) {
                continue;
            }

            foreach ($module['implementations'] as $contract => $implementation) {
                if (! str_starts_with($implementation, 'unresolvable:')
                    || preg_match('/(?:Authorization|Access)$/', class_basename($contract)) !== 1) {
                    continue;
                }

                $findings[] = new ConsumerAuditFinding(
                    code: 'consumer.missing_auth_binding',
                    severity: 'error',
                    package: $package,
                    path: 'runtime/container',
                    line: null,
                    symbol: $contract,
                    message: 'An enabled module authorization contract cannot be resolved.',
                    remediation: 'Bind an application authorization adapter for the package contract.',
                );
            }

            foreach ($module['schedules'] as $schedule) {
                if (! $schedule['required'] || $schedule['registered']) {
                    continue;
                }

                $findings[] = new ConsumerAuditFinding(
                    code: 'consumer.missing_required_schedule',
                    severity: 'error',
                    package: $package,
                    path: 'runtime/schedule',
                    line: null,
                    symbol: $schedule['command'],
                    message: 'An enabled package feature is missing its required scheduler entry.',
                    remediation: 'Register the documented package command in the application scheduler.',
                );
            }
        }

        return $findings;
    }

    /**
     * @param  SuiteConfigurationReport  $report
     * @return list<ConsumerAuditFinding>
     */
    private function routeFindings(array $report): array
    {
        $acceptedMiddleware = $this->authenticationMiddleware();
        $enabled = array_fill_keys(array_keys(array_filter(
            $report['modules'],
            static fn (array $module): bool => $module['enabled'],
        )), true);
        $actions = $this->catalog->managementActions();
        $findings = [];
        $routedPackages = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $action = $this->routeActionClass($route);

            if ($action === null) {
                continue;
            }

            $package = $this->managementPackage($action, $actions);

            if ($package === null || ! isset($enabled[$package])) {
                continue;
            }

            $routedPackages[$package] = true;

            $middleware = array_values(array_filter(
                $route->gatherMiddleware(),
                'is_string',
            ));
            $authenticated = collect($middleware)->contains(
                static function (string $middleware) use ($acceptedMiddleware): bool {
                    foreach ($acceptedMiddleware as $accepted) {
                        if ($middleware === $accepted || str_starts_with($middleware, $accepted.':')) {
                            return true;
                        }
                    }

                    return false;
                },
            );

            if ($authenticated) {
                continue;
            }

            $findings[] = new ConsumerAuditFinding(
                code: 'consumer.unsafe_management_route',
                severity: 'error',
                package: $package,
                path: 'runtime/routes',
                line: null,
                symbol: $this->routeSymbol($route),
                message: 'A package management route has no recognized authentication middleware.',
                remediation: 'Attach auth, auth:*, or a configured equivalent to the management route.',
            );
        }

        $alreadyUnsafe = array_fill_keys(array_values(array_filter(array_map(
            static fn (ConsumerAuditFinding $finding): ?string => $finding->package,
            $findings,
        ))), true);

        foreach (array_keys($routedPackages) as $package) {
            if (isset($alreadyUnsafe[$package])
                || ! $this->doctorReportsUnsafeAuthorization($package, $report)) {
                continue;
            }

            $findings[] = new ConsumerAuditFinding(
                code: 'consumer.unsafe_management_route',
                severity: 'error',
                package: $package,
                path: 'runtime/routes',
                line: null,
                symbol: $package.':management-authorization',
                message: 'A package Doctor reports unhealthy management authorization.',
                remediation: 'Configure the package authorization adapter or management policy required by its Doctor.',
            );
        }

        return $findings;
    }

    /**
     * @param  SuiteConfigurationReport  $report
     */
    private function doctorReportsUnsafeAuthorization(string $package, array $report): bool
    {
        $doctor = $report['modules'][$package]['doctor'] ?? null;

        if (! is_string($doctor) || $doctor === '') {
            return false;
        }

        try {
            $output = new BufferedOutput;
            $this->console->call(
                $doctor,
                ['--strict' => true, '--format' => 'json'],
                $output,
            );
            $decoded = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }

        return is_array($decoded) && $this->containsFailedAuthorizationCheck($decoded);
    }

    /**
     * @param  array<mixed, mixed>  $value
     */
    private function containsFailedAuthorizationCheck(array $value, string $parentKey = ''): bool
    {
        $identifier = $value['key'] ?? $value['name'] ?? $parentKey;

        if (is_string($identifier)
            && isset($value['passed'])
            && $value['passed'] === false
            && $this->authorizationIdentifier($identifier)) {
            return true;
        }

        foreach ($value as $key => $nested) {
            $keyName = is_string($key) ? $key : $parentKey;

            if ($nested === false && $this->authorizationIdentifier($keyName)) {
                return true;
            }

            if (is_array($nested) && $this->containsFailedAuthorizationCheck($nested, $keyName)) {
                return true;
            }
        }

        return false;
    }

    private function authorizationIdentifier(string $identifier): bool
    {
        $identifier = strtolower($identifier);

        return str_contains($identifier, 'authorization')
            || str_contains($identifier, 'management.access')
            || str_contains($identifier, 'access.management')
            || str_contains($identifier, 'management.policy')
            || str_contains($identifier, 'policy.management');
    }

    /**
     * @param  SuiteConfigurationReport  $report
     * @return list<ConsumerAuditFinding>
     */
    private function generatedTypeFindings(array $report): array
    {
        if (! ($report['modules']['data']['enabled'] ?? false)) {
            return [];
        }

        try {
            $exitCode = $this->console->call(
                'nvl:data:types:check',
                ['--fail-on-warning' => true],
                new BufferedOutput,
            );
        } catch (Throwable) {
            $exitCode = 1;
        }

        if ($exitCode === 0) {
            return [];
        }

        return [new ConsumerAuditFinding(
            code: 'consumer.stale_generated_contract',
            severity: 'error',
            package: 'data',
            path: 'runtime/generated-types',
            line: null,
            symbol: 'nvl:data:types:check',
            message: 'Generated NVL TypeScript declarations are missing or stale.',
            remediation: 'Regenerate the application TypeScript contracts and commit the result.',
        )];
    }

    /**
     * @return list<ConsumerAuditFinding>
     */
    private function skillFindings(): array
    {
        try {
            $checks = $this->skills->inspect(strict: true)['checks'];
        } catch (Throwable) {
            $checks = [[
                'module' => null,
                'skill' => 'manifest',
                'status' => 'invalid',
                'passed' => false,
                'severity' => 'error',
                'message' => 'The managed Suite skill report is unavailable.',
            ]];
        }

        $findings = [];

        foreach ($checks as $check) {
            if ($check['passed']) {
                continue;
            }

            $skill = $check['skill'];
            $module = $check['module'];

            $findings[] = new ConsumerAuditFinding(
                code: 'consumer.stale_suite_skill',
                severity: 'error',
                package: is_string($module) ? $module : null,
                path: 'runtime/skills',
                line: null,
                symbol: $skill,
                message: 'An enabled Suite skill is missing, unmanaged, modified, or stale.',
                remediation: 'Publish the managed Suite skills and resolve any reported ownership conflict.',
            );
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function authenticationMiddleware(): array
    {
        $configured = $this->configuration->get(
            'nvl-suite.consumer_audit.authentication_middleware',
            ['auth'],
        );

        if (! is_array($configured) || $configured === []) {
            throw new InvalidArgumentException(
                'nvl-suite.consumer_audit.authentication_middleware must contain middleware names.',
            );
        }

        $middleware = [];

        foreach ($configured as $name) {
            if (! is_string($name) || trim($name) === '' || str_contains($name, ':')) {
                throw new InvalidArgumentException(
                    'Consumer audit authentication middleware names must be non-empty base names.',
                );
            }

            $middleware[] = trim($name);
        }

        return array_values(array_unique($middleware));
    }

    private function routeActionClass(Route $route): ?string
    {
        $action = $route->getActionName();

        if ($action === 'Closure') {
            return null;
        }

        return explode('@', $action, 2)[0] ?: null;
    }

    /**
     * @param  array<string, list<class-string|string>>  $definitions
     */
    private function managementPackage(string $action, array $definitions): ?string
    {
        foreach ($definitions as $package => $candidates) {
            foreach ($candidates as $candidate) {
                $matches = str_ends_with($candidate, '\\')
                    ? str_starts_with($action, $candidate)
                    : $action === $candidate;

                if ($matches) {
                    return $package;
                }
            }
        }

        return null;
    }

    private function routeSymbol(Route $route): string
    {
        $name = $route->getName();

        return is_string($name) && $name !== ''
            ? $name
            : implode('|', array_values(array_filter($route->methods(), 'is_string'))).' '.$route->uri();
    }

    /**
     * @param  list<ConsumerAuditFinding>  $findings
     * @return list<ConsumerAuditFinding>
     */
    private function sortedUnique(array $findings): array
    {
        $unique = [];

        foreach ($findings as $finding) {
            $unique[$finding->code.'|'.$finding->symbol] = $finding;
        }

        ksort($unique);

        return array_values($unique);
    }
}
