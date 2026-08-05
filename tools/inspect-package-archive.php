<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php tools/inspect-package-archive.php <archive.zip> <package>\n");

    exit(2);
}

[, $archivePath, $package] = $argv;

if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $package) !== 1) {
    fwrite(STDERR, "Package [{$package}] is not a valid NVL package identifier.\n");

    exit(2);
}

if (! is_file($archivePath)) {
    fwrite(STDERR, "Archive [{$archivePath}] does not exist.\n");

    exit(2);
}

$zip = new ZipArchive;
if ($zip->open($archivePath) !== true) {
    fwrite(STDERR, "Archive [{$archivePath}] cannot be opened.\n");

    exit(2);
}

$entries = [];
for ($index = 0; $index < $zip->numFiles; $index++) {
    $name = $zip->getNameIndex($index);
    if (is_string($name)) {
        $normalizedName = rtrim($name, '/');

        if ($normalizedName !== '') {
            $entries[] = $normalizedName;
        }
    }
}
$manifestContents = $zip->getFromName('composer.json');
$zip->close();

if (! is_string($manifestContents)) {
    fwrite(STDERR, "Archive [{$archivePath}] has no root composer.json.\n");

    exit(1);
}

try {
    $manifest = json_decode($manifestContents, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fwrite(STDERR, "Archive has an invalid root composer.json: {$exception->getMessage()}\n");

    exit(1);
}

if (! is_array($manifest)) {
    fwrite(STDERR, "Archive has an invalid root composer.json.\n");

    exit(1);
}

$expectedPackageName = "nvl/{$package}";

if (($manifest['name'] ?? null) !== $expectedPackageName) {
    fwrite(
        STDERR,
        "Archive composer.json must declare package [{$expectedPackageName}].\n",
    );

    exit(1);
}

$forbiddenPathPrefixes = [
    '.git',
    '.gitattributes',
    '.github',
    '.gitignore',
    '.temp',
    'composer.lock',
    'build',
    'coverage',
    'node_modules',
    '.phpunit.cache',
    '.phpunit.result.cache',
    'phpstan.neon',
    'phpstan.neon.dist',
    'phpunit.xml',
    'phpunit.xml.dist',
    'phpunit.path-coverage.xml',
    'tests',
    'tests/.pest',
    'vendor',
];

$forbiddenEntries = array_values(array_filter(
    $entries,
    static function (string $entry) use ($forbiddenPathPrefixes): bool {
        $normalizedEntry = rtrim($entry, '/');

        foreach ($forbiddenPathPrefixes as $prefix) {
            if ($normalizedEntry === $prefix || str_starts_with($normalizedEntry, "{$prefix}/")) {
                return true;
            }
        }

        return false;
    },
));

if ($forbiddenEntries !== []) {
    fwrite(
        STDERR,
        'Archive contains build-only files: '.implode(', ', array_slice($forbiddenEntries, 0, 10))."\n",
    );

    exit(1);
}

$requiredFiles = [
    'composer.json',
    'README.md',
    'LICENSE',
    'CHANGELOG.md',
    'UPGRADING.md',
    'SECURITY.md',
    'CONTRIBUTING.md',
    "resources/boost/skills/nvl-{$package}/SKILL.md",
    "resources/boost/skills/nvl-{$package}/agents/openai.yaml",
];
$requiredDirectories = ['src'];

if (in_array($package, [
    'activity',
    'auth',
    'comments',
    'content',
    'forms',
    'media',
    'metafields',
    'pages',
    'seo',
    'settings',
    'taxonomy',
    'translations',
    'templates',
], true)) {
    $requiredDirectories[] = 'database/migrations';
}

if ($package === 'activity') {
    $requiredFiles = [
        ...$requiredFiles,
        'config/activity.php',
        'lang/en/activity/general.php',
        'lang/bg/activity/general.php',
        'routes/api.php',
        'src/Support/activitylog_compatibility.php',
    ];
}

$missing = [];
foreach ($requiredFiles as $requiredFile) {
    if (! in_array($requiredFile, $entries, true)) {
        $missing[] = $requiredFile;
    }
}

foreach ($requiredDirectories as $requiredDirectory) {
    $found = array_filter(
        $entries,
        static function (string $entry) use ($requiredDirectory): bool {
            $normalizedEntry = rtrim($entry, '/');

            return $normalizedEntry === $requiredDirectory
                || str_starts_with($normalizedEntry, "{$requiredDirectory}/");
        },
    );

    if ($found === []) {
        $missing[] = $requiredDirectory;
    }
}

if ($missing !== []) {
    fwrite(STDERR, 'Archive is missing: '.implode(', ', $missing)."\n");

    exit(1);
}

$autoload = $manifest['autoload'] ?? null;
$autoloadErrors = [];

if (! is_array($autoload)) {
    $autoloadErrors[] = 'composer.json must declare an autoload object';
} else {
    $psr4 = $autoload['psr-4'] ?? null;
    $namespace = implode('', array_map(
        static fn (string $segment): string => ucfirst($segment),
        explode('-', $package),
    ));
    $expectedNamespace = "Nvl\\{$namespace}\\";

    if (! is_array($psr4)) {
        $autoloadErrors[] = 'composer.json must declare an autoload.psr-4 object';
    } else {
        if (($psr4[$expectedNamespace] ?? null) !== 'src/') {
            $autoloadErrors[] = "autoload.psr-4 must map [{$expectedNamespace}] to [src/]";
        }

        foreach ($psr4 as $autoloadNamespace => $autoloadPaths) {
            if (! is_string($autoloadNamespace) || trim($autoloadNamespace) === '') {
                $autoloadErrors[] = 'autoload.psr-4 contains an invalid namespace';

                continue;
            }

            if (is_string($autoloadPaths)) {
                $autoloadPaths = [$autoloadPaths];
            }

            if (! is_array($autoloadPaths) || ! array_is_list($autoloadPaths)) {
                $autoloadErrors[] = "autoload.psr-4 namespace [{$autoloadNamespace}] has invalid paths";

                continue;
            }

            foreach ($autoloadPaths as $autoloadPath) {
                if (! is_string($autoloadPath) || trim($autoloadPath) === '') {
                    $autoloadErrors[] = "autoload.psr-4 namespace [{$autoloadNamespace}] has an invalid path";

                    continue;
                }

                $normalizedAutoloadPath = rtrim($autoloadPath, '/');
                $segments = explode('/', str_replace('\\', '/', $normalizedAutoloadPath));
                $isRootRelative = $normalizedAutoloadPath !== ''
                    && ! str_starts_with($normalizedAutoloadPath, '/')
                    && preg_match('/^[A-Za-z]:[\\\\\/]/D', $normalizedAutoloadPath) !== 1
                    && ! in_array('.', $segments, true)
                    && ! in_array('..', $segments, true);
                $pathExists = $isRootRelative && array_filter(
                    $entries,
                    static fn (string $entry): bool => $entry === $normalizedAutoloadPath
                        || str_starts_with($entry, "{$normalizedAutoloadPath}/"),
                ) !== [];

                if (! $pathExists) {
                    $autoloadErrors[] = "autoload.psr-4 path [{$autoloadPath}] is missing from the archive root";
                }
            }
        }
    }

    $autoloadFiles = $autoload['files'] ?? [];

    if (! is_array($autoloadFiles) || ! array_is_list($autoloadFiles)) {
        $autoloadErrors[] = 'composer.json autoload.files must be a list';
    } else {
        foreach ($autoloadFiles as $autoloadFile) {
            if (! is_string($autoloadFile) || trim($autoloadFile) === '') {
                $autoloadErrors[] = 'composer.json autoload.files contains an invalid path';

                continue;
            }

            $segments = explode('/', str_replace('\\', '/', $autoloadFile));
            $isRootRelative = ! str_starts_with($autoloadFile, '/')
                && preg_match('/^[A-Za-z]:[\\\\\/]/D', $autoloadFile) !== 1
                && ! in_array('.', $segments, true)
                && ! in_array('..', $segments, true);

            if (! $isRootRelative || ! in_array($autoloadFile, $entries, true)) {
                $autoloadErrors[] = "autoload.files path [{$autoloadFile}] is missing from the archive root";
            }
        }
    }
}

if ($autoloadErrors !== []) {
    fwrite(STDERR, 'Archive Composer autoload is invalid: '.implode('; ', $autoloadErrors)."\n");

    exit(1);
}

fwrite(STDOUT, "Archive for nvl/{$package} contains all required assets.\n");
