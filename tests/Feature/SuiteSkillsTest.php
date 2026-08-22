<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Nvl\Suite\Services\SuiteSkillManager;
use Nvl\Suite\Support\SuiteModuleCatalog;

/**
 * @param  list<string>  $enabledModules
 */
function suiteSkillTestManager(
    string $applicationRoot,
    array $enabledModules = ['auth'],
    string $version = '1.0.7',
): SuiteSkillManager {
    $suiteRoot = dirname(__DIR__, 2);
    $family = require $suiteRoot.'/tools/package-family.php';
    $modules = array_fill_keys($family['packages'], false);

    foreach ($enabledModules as $module) {
        $modules[$module] = true;
    }

    return new SuiteSkillManager(
        filesystem: new Filesystem,
        catalog: new SuiteModuleCatalog(new Repository([
            'nvl-suite' => ['modules' => $modules],
        ])),
        suiteRoot: $suiteRoot,
        applicationRoot: $applicationRoot,
        suiteVersion: $version,
    );
}

/**
 * @return array<string, non-empty-string>
 */
function suiteSkillTestSnapshot(string $directory): array
{
    $filesystem = new Filesystem;

    if (! $filesystem->isDirectory($directory)) {
        return [];
    }

    $files = [];
    $prefixLength = mb_strlen(rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);

    foreach ($filesystem->allFiles($directory, hidden: true) as $file) {
        $relativePath = str_replace(
            DIRECTORY_SEPARATOR,
            '/',
            mb_substr($file->getPathname(), $prefixLength),
        );
        $hash = $filesystem->hash($file->getPathname(), 'sha256');

        if (is_string($hash) && $hash !== '') {
            $files[$relativePath] = $hash;
        }
    }

    ksort($files);

    return $files;
}

it('registers one aggregate skill tag for every effective Suite module', function (): void {
    $paths = ServiceProvider::pathsToPublish(group: 'suite-skills');
    $modules = app(SuiteModuleCatalog::class)->effectiveModules();
    $root = dirname(__DIR__, 2);

    expect($paths)->toHaveCount(count($modules));

    foreach ($modules as $module) {
        expect($paths)->toHaveKey(
            $root.'/resources/boost/skills/nvl-'.$module,
            base_path('.agents/skills/nvl-'.$module),
        );
    }
});

it('publishes all twenty full-suite skills and one aggregate ownership manifest', function (): void {
    $workspace = sys_get_temp_dir().'/nvl-suite-skills-'.bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $family = require dirname(__DIR__, 2).'/tools/package-family.php';

    try {
        $manager = suiteSkillTestManager($workspace, $family['packages']);
        $report = $manager->publish();
        $manifest = json_decode(
            $filesystem->get($manager->manifestPath()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($report['healthy'])->toBeTrue()
            ->and($report['results'])->toHaveCount(20)
            ->and(array_unique(array_column($report['results'], 'status')))->toBe(['installed'])
            ->and($manifest['skills'] ?? [])->toHaveCount(20)
            ->and($manager->inspect(strict: true)['healthy'])->toBeTrue();
    } finally {
        $filesystem->deleteDirectory($workspace);
    }
});

it('publishes effective skills with versioned ownership and safely updates only managed skills', function (): void {
    $workspace = sys_get_temp_dir().'/nvl-suite-skills-'.bin2hex(random_bytes(8));
    $filesystem = new Filesystem;

    try {
        $filesystem->ensureDirectoryExists($workspace.'/.agents/skills/application-authored');
        $filesystem->ensureDirectoryExists($workspace.'/.agents/skills/nvl-application');
        $filesystem->put(
            $workspace.'/.agents/skills/application-authored/SKILL.md',
            'application-owned',
        );
        $filesystem->put(
            $workspace.'/.agents/skills/nvl-application/SKILL.md',
            'application-owned-nvl',
        );
        $applicationSkills = [
            'application-authored/SKILL.md' => 'application-owned',
            'nvl-application/SKILL.md' => 'application-owned-nvl',
        ];
        $manager = suiteSkillTestManager($workspace);
        $report = $manager->publish();
        $manifest = json_decode(
            $filesystem->get($manager->manifestPath()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($report['healthy'])->toBeTrue()
            ->and(array_column($report['results'], 'skill'))->toBe(['nvl-data', 'nvl-auth'])
            ->and(array_column($report['results'], 'status'))->toBe(['installed', 'installed'])
            ->and($manifest['owner'] ?? null)->toBe(SuiteSkillManager::OWNER)
            ->and($manifest['suite_version'] ?? null)->toBe('1.0.7')
            ->and(array_keys($manifest['skills'] ?? []))->toBe(['nvl-auth', 'nvl-data'])
            ->and($manager->inspect()['healthy'])->toBeTrue();

        foreach ($applicationSkills as $relativePath => $contents) {
            expect($filesystem->get($workspace.'/.agents/skills/'.$relativePath))->toBe($contents);
        }

        $unchanged = $manager->publish();

        expect(array_column($unchanged['results'], 'status'))->toBe(['unchanged', 'unchanged']);

        $authSkill = $workspace.'/.agents/skills/nvl-auth/SKILL.md';
        $filesystem->append($authSkill, "\nlocal customization\n");
        $conflict = $manager->publish();

        expect($conflict['healthy'])->toBeFalse()
            ->and(collect($conflict['results'])->firstWhere('skill', 'nvl-auth')['status'] ?? null)
            ->toBe('conflict')
            ->and($filesystem->get($authSkill))->toContain('local customization');

        $forced = $manager->publish(force: true);

        expect($forced['healthy'])->toBeTrue()
            ->and(collect($forced['results'])->firstWhere('skill', 'nvl-auth')['status'] ?? null)
            ->toBe('forced')
            ->and($filesystem->get($authSkill))
            ->toBe($filesystem->get($manager->sourcePath('auth').'/SKILL.md'));

        foreach ($applicationSkills as $relativePath => $contents) {
            expect($filesystem->get($workspace.'/.agents/skills/'.$relativePath))->toBe($contents);
        }
    } finally {
        $filesystem->deleteDirectory($workspace);
    }
});

it('never overwrites an unmanaged Suite-named skill even when forced', function (): void {
    $workspace = sys_get_temp_dir().'/nvl-suite-skills-'.bin2hex(random_bytes(8));
    $filesystem = new Filesystem;

    try {
        $filesystem->ensureDirectoryExists($workspace.'/.agents/skills/nvl-auth');
        $filesystem->put(
            $workspace.'/.agents/skills/nvl-auth/SKILL.md',
            'application-authored-auth-guidance',
        );
        $manager = suiteSkillTestManager($workspace);
        $report = $manager->publish(force: true);
        $manifest = json_decode(
            $filesystem->get($manager->manifestPath()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $doctor = $manager->inspect();

        expect($report['healthy'])->toBeFalse()
            ->and(collect($report['results'])->firstWhere('skill', 'nvl-auth')['status'] ?? null)
            ->toBe('conflict')
            ->and($filesystem->get($workspace.'/.agents/skills/nvl-auth/SKILL.md'))
            ->toBe('application-authored-auth-guidance')
            ->and($manifest['skills'] ?? [])->toHaveKey('nvl-data')
            ->not->toHaveKey('nvl-auth')
            ->and($doctor['healthy'])->toBeFalse()
            ->and(collect($doctor['checks'])->firstWhere('skill', 'nvl-auth')['status'] ?? null)
            ->toBe('unmanaged');
    } finally {
        $filesystem->deleteDirectory($workspace);
    }
});

it('detects managed content drift without changing any application files', function (): void {
    $workspace = sys_get_temp_dir().'/nvl-suite-skills-'.bin2hex(random_bytes(8));
    $filesystem = new Filesystem;

    try {
        $manager = suiteSkillTestManager($workspace);

        expect($manager->publish()['healthy'])->toBeTrue();

        $filesystem->append(
            $workspace.'/.agents/skills/nvl-data/SKILL.md',
            "\nlocal drift\n",
        );
        $before = suiteSkillTestSnapshot($workspace);
        $report = $manager->inspect();
        $after = suiteSkillTestSnapshot($workspace);

        expect($report['healthy'])->toBeFalse()
            ->and(collect($report['checks'])->firstWhere('skill', 'nvl-data')['status'] ?? null)
            ->toBe('modified')
            ->and($after)->toBe($before);
    } finally {
        $filesystem->deleteDirectory($workspace);
    }
});

it('exposes machine-readable publish and read-only doctor commands', function (): void {
    $workspace = sys_get_temp_dir().'/nvl-suite-skills-'.bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $originalManager = app(SuiteSkillManager::class);

    try {
        app()->instance(SuiteSkillManager::class, suiteSkillTestManager($workspace));

        expect(Artisan::call('nvl:suite:skills:publish', ['--format' => 'json']))->toBe(0);

        $publication = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $beforeDoctor = suiteSkillTestSnapshot($workspace);

        expect($publication['healthy'] ?? null)->toBeTrue()
            ->and(Artisan::call('nvl:suite:skills:doctor', [
                '--strict' => true,
                '--format' => 'json',
            ]))->toBe(0);

        $doctor = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($doctor['healthy'] ?? null)->toBeTrue()
            ->and(suiteSkillTestSnapshot($workspace))->toBe($beforeDoctor)
            ->and(Artisan::call('nvl:suite:skills:doctor', ['--format' => 'yaml']))->toBe(2);
    } finally {
        app()->instance(SuiteSkillManager::class, $originalManager);
        $filesystem->deleteDirectory($workspace);
    }
});
