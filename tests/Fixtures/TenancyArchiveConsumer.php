<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** Builds isolated consumers from archives and offline baseline dependencies. */
final class TenancyArchiveConsumer
{
    /**
     * Run one independent Composer-installed consumer and remove its artifacts.
     *
     * @return array<string, mixed>
     */
    public static function run(bool $filterable): array
    {
        return self::runMode($filterable ? 'filterable' : 'minimal');
    }

    /**
     * Run an independent Activity consumer without the optional Auth package.
     *
     * @return array<string, mixed>
     */
    public static function runActivity(): array
    {
        return self::runMode('activity');
    }

    /**
     * Run an independent Translatable consumer without Auth or Suite.
     *
     * @return array<string, mixed>
     */
    public static function runTranslatable(): array
    {
        return self::runMode('translatable');
    }

    /**
     * Build and run one selected standalone archive profile.
     *
     * @return array<string, mixed>
     */
    private static function runMode(string $mode): array
    {
        $root = dirname(__DIR__, 2);
        $workspace = sys_get_temp_dir().'/nvl-tenancy-consumer-'.bin2hex(random_bytes(8));
        $filesystem = new Filesystem;
        $filterable = $mode === 'filterable';
        $activity = $mode === 'activity';
        $translatable = $mode === 'translatable';
        $packages = ['support', 'data', 'tenancy', ...($filterable ? ['filterable'] : []), ...($activity ? ['activity'] : []), ...($translatable ? ['translatable'] : [])];

        try {
            $filesystem->mkdir([$workspace.'/app', $workspace.'/archives', $workspace.'/bootstrap/cache', $workspace.'/config', $workspace.'/storage/framework/views']);
            $repositories = [];
            foreach ($packages as $package) {
                self::command(['composer', 'archive', '--format=zip', '--file='.$package, '--dir='.$workspace.'/archives', '--no-interaction'], $root.'/packages/nvl/'.$package);
                $directory = $workspace.'/packages/'.$package;
                $filesystem->mkdir($directory);
                self::command(['unzip', '-q', $workspace.'/archives/'.$package.'.zip', '-d', $directory], $workspace);
                if (is_dir($directory.'/tests') || is_dir($directory.'/vendor')) {
                    throw new RuntimeException('The package archive contains development files.');
                }
                foreach (['composer.json', 'README.md', 'LICENSE', 'src', 'resources/boost/skills'] as $required) {
                    if (! file_exists($directory.'/'.$required)) {
                        throw new RuntimeException('The package archive omits '.$required);
                    }
                }
                $repositories[] = ['type' => 'path', 'url' => $directory, 'options' => ['versions' => ['nvl/'.$package => '2.0.0'], 'symlink' => false]];
            }

            $installed = json_decode((string) file_get_contents($root.'/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
            foreach ($installed['packages'] as $package) {
                if (str_starts_with($package['name'], 'nvl/')) {
                    continue;
                }
                $path = realpath($root.'/vendor/composer/'.$package['install-path']);
                if ($path === false) {
                    throw new RuntimeException('Missing installed dependency '.$package['name']);
                }
                $package['dist'] = ['type' => 'path', 'url' => $path, 'reference' => $package['dist']['reference'] ?? null];
                unset($package['source'], $package['install-path'], $package['installation-source']);
                $repositories[] = ['type' => 'package', 'package' => $package];
            }
            $repositories[] = ['packagist.org' => false];
            $manifest = [
                'name' => 'consumer/tenancy-proof',
                'require' => [
                    'php' => '^8.4',
                    'nvl/tenancy' => '2.0.0',
                    ...($filterable ? ['nvl/filterable' => '2.0.0'] : []),
                    ...($activity ? ['nvl/activity' => '2.0.0'] : []),
                    ...($translatable ? ['nvl/translatable' => '2.0.0'] : []),
                ],
                'repositories' => $repositories,
                'autoload' => ['psr-4' => [
                    'Nvl\\Data\\Tests\\Fixtures\\' => 'fixtures/data/',
                    ...($filterable ? ['Nvl\\Filterable\\Tests\\Fixtures\\' => 'fixtures/filterable/'] : []),
                ]],
                'config' => ['allow-plugins' => false],
            ];
            $filesystem->dumpFile($workspace.'/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $filesystem->copy($root.'/packages/nvl/data/tests/Fixtures/OwnershipProjectionData.php', $workspace.'/fixtures/data/OwnershipProjectionData.php');
            if ($filterable) {
                foreach (['PredicateRecord', 'PredicateGroup', 'RelatedPredicateRecord'] as $fixture) {
                    $filesystem->copy($root.'/packages/nvl/filterable/tests/Fixtures/'.$fixture.'.php', $workspace.'/fixtures/filterable/'.$fixture.'.php');
                }
            }
            self::command(['composer', 'update', '--no-dev', '--no-plugins', '--no-scripts', '--no-audit', '--no-interaction'], $workspace);
            self::command(['composer', 'check-platform-reqs', '--no-dev'], $workspace);
            $filesystem->dumpFile($workspace.'/bootstrap/providers.php', '<?php return [];');
            $filesystem->dumpFile($workspace.'/bootstrap/app.php', <<<'BOOT'
<?php
return Illuminate\Foundation\Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware()->withExceptions()->create();
BOOT);
            $filesystem->dumpFile($workspace.'/artisan', <<<'ARTISAN'
<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
exit($app->handleCommand(new Symfony\Component\Console\Input\ArgvInput));
ARTISAN);
            $filesystem->copy(__DIR__.'/tenancy-consumer.php', $workspace.'/consumer.php');
            self::command([PHP_BINARY, 'artisan', 'package:discover', '--no-interaction'], $workspace);
            self::command([PHP_BINARY, 'artisan', 'config:cache', '--no-interaction'], $workspace);

            return json_decode(self::command([PHP_BINARY, 'consumer.php', $mode], $workspace), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            $filesystem->remove($workspace);
        }
    }

    /**
     * Run an isolated process with SQLite and no external application services.
     *
     * @param  list<string>  $command
     */
    private static function command(array $command, string $directory): string
    {
        $process = new Process($command, $directory, [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
            'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array', 'COMPOSER_DISABLE_NETWORK' => '1',
        ], timeout: 120);
        $process->mustRun();

        return $process->getOutput();
    }
}
