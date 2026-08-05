<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Static distribution and architecture validation for the NVL package family.
 */
$root = dirname(__DIR__);
$autoload = "{$root}/vendor/autoload.php";

if (! is_file($autoload)) {
    fwrite(STDERR, "Install Composer dependencies before validating the package family.\n");

    exit(2);
}

require_once $autoload;

$catalog = require __DIR__.'/package-family.php';
$packages = $catalog['packages'];
$internalDependencies = $catalog['internal_dependencies'];
$databaseTested = $catalog['database_tested'];
$stateful = $catalog['stateful'];
$managementConfiguration = [
    'activity' => ['activity.php', "'enabled' => false"],
    'auth' => ['nvl-auth.php', "'enabled' => false"],
    'comments' => ['comments.php', "'enabled' => false"],
    'content' => ['content.php', "'enabled' => false"],
    'data' => ['nvl-data.php', "'enabled' => false"],
    'forms' => ['forms.php', "'enabled' => false"],
    'media' => ['media.php', "'api_enabled' => false"],
    'metafields' => ['metafields.php', "'enabled' => false"],
    'pages' => ['pages.php', "'enabled' => false"],
    'seo' => ['seo.php', "'enabled' => false"],
    'settings' => ['settings.php', "'enabled' => false"],
    'translations' => ['translations.php', "'enabled' => false"],
    'templates' => ['templates.php', "'enabled' => false"],
];
$requiredFiles = [
    'README.md',
    'LICENSE',
    'CHANGELOG.md',
    'UPGRADING.md',
    'SECURITY.md',
    'CONTRIBUTING.md',
    'composer.json',
    'phpstan.neon.dist',
    'phpunit.xml.dist',
];
$scanRoots = [
    'src',
    'config',
    'routes',
    'database',
    'resources',
    'README.md',
    'UPGRADING.md',
    'SECURITY.md',
    'CONTRIBUTING.md',
    'CHANGELOG.md',
];
$serviceLocatorAllowlist = [
    'media' => ['src/Traits/InteractsWithMedia.php'],
    'seo' => ['src/Providers/SeoServiceProvider.php'],
    'settings' => ['src/Testing/InteractsWithSettings.php'],
    'taxonomy' => [
        'src/Concerns/BelongsToTaxonomy.php',
        'src/Concerns/HasTaxonomies.php',
    ],
    'translatable' => ['src/SelfTranslatable.php'],
];
$errors = [];
$familySource = '';
foreach ($packages as $familyPackage) {
    $familySource .= selfReadTree("{$root}/packages/nvl/{$familyPackage}/src");
}
$knownSymbols = [
    'Data' => true,
    'Model' => true,
    'Optional' => true,
    'Request' => true,
    'RoundingMode' => true,
];
$sourceIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    "{$root}/packages/nvl",
    FilesystemIterator::SKIP_DOTS,
));
foreach ($sourceIterator as $sourceFile) {
    if ($sourceFile->isFile()
        && $sourceFile->getExtension() === 'php'
        && str_contains($sourceFile->getPathname(), '/src/')) {
        $knownSymbols[pathinfo($sourceFile->getFilename(), PATHINFO_FILENAME)] = true;
    }
}

$fail = static function (string $package, string $message) use (&$errors): void {
    $errors[] = "nvl/{$package}: {$message}";
};

$discoveredPackages = array_map(
    'basename',
    glob("{$root}/packages/nvl/*", GLOB_ONLYDIR) ?: [],
);
$expectedPackages = $packages;
sort($discoveredPackages);
sort($expectedPackages);

if ($discoveredPackages !== $expectedPackages) {
    $fail(
        'family',
        'canonical catalog does not match package directories; discovered ['.
        implode(', ', $discoveredPackages).'], expected ['.implode(', ', $expectedPackages).']',
    );
}

$contractBaselinePath = "{$root}/tools/package-contracts.json";
$contractBaseline = is_file($contractBaselinePath)
    ? json_decode((string) file_get_contents($contractBaselinePath), true)
    : null;
$contractPackages = is_array($contractBaseline)
    && is_array($contractBaseline['packages'] ?? null)
        ? array_keys($contractBaseline['packages'])
        : [];
sort($contractPackages);

if ($contractPackages !== $expectedPackages) {
    $fail('family', 'public-contract baseline does not contain exactly the canonical package catalog');
}

$rootManifest = json_decode((string) file_get_contents("{$root}/composer.json"), true);
$rootRequirements = is_array($rootManifest['require'] ?? null) ? $rootManifest['require'] : [];
$rootPackages = array_map(
    static fn (string $dependency): string => substr($dependency, 4),
    array_values(array_filter(
        array_keys($rootRequirements),
        static fn (mixed $dependency): bool => is_string($dependency)
            && str_starts_with($dependency, 'nvl/'),
    )),
);
sort($rootPackages);

if ($rootPackages !== $expectedPackages) {
    $fail('family', 'root Composer requirements do not contain exactly the canonical package catalog');
}

$rootReadme = (string) file_get_contents("{$root}/README.md");
preg_match_all('/^\| `nvl\/([a-z0-9-]+)` \|/m', $rootReadme, $rootReadmeRows);
$documentedRootPackages = $rootReadmeRows[1] ?? [];
sort($documentedRootPackages);

if ($documentedRootPackages !== $expectedPackages) {
    $fail('family', 'root README package table does not contain exactly the canonical package catalog');
}

$packageCatalog = (string) file_get_contents("{$root}/packages.md");
preg_match_all('/^## `nvl\/([a-z0-9-]+)`$/m', $packageCatalog, $packageCatalogHeadings);
$documentedCatalogPackages = $packageCatalogHeadings[1] ?? [];
sort($documentedCatalogPackages);

if ($documentedCatalogPackages !== $expectedPackages) {
    $fail('family', 'packages.md does not contain exactly one section for every canonical package');
}

preg_match_all('/^- `([a-z0-9-]+)-skills`$/m', $rootReadme, $rootSkillTags);
$documentedSkillTags = $rootSkillTags[1] ?? [];
sort($documentedSkillTags);

if ($documentedSkillTags !== $expectedPackages) {
    $fail('family', 'root README skill tags do not contain exactly every canonical package');
}

$workflowPath = "{$root}/.github/workflows/package-quality.yml";
$workflow = (string) file_get_contents($workflowPath);
$workflowConfiguration = Yaml::parseFile($workflowPath);

if (! is_array($workflowConfiguration)) {
    $fail('family', 'package-quality CI must be a valid YAML mapping');
    $workflowConfiguration = [];
}

if (! str_contains($workflow, 'composer contracts:check')) {
    $fail('family', 'package-quality CI must enforce the public-contract baseline');
}

preg_match('/for package in ([a-z0-9 -]+); do/', $workflow, $databaseLoop);
$databaseMatrixPackages = isset($databaseLoop[1])
    ? preg_split('/\s+/', trim($databaseLoop[1])) ?: []
    : [];
$expectedDatabaseTested = $databaseTested;
sort($databaseMatrixPackages);
sort($expectedDatabaseTested);

if ($databaseMatrixPackages !== $expectedDatabaseTested) {
    $fail('family', 'database CI matrix does not contain exactly every database-tested package');
}

foreach (['line-coverage', 'branch-coverage'] as $coverageJob) {
    $coveragePackages = $workflowConfiguration['jobs'][$coverageJob]['strategy']['matrix']['package'] ?? [];

    if (! is_array($coveragePackages)) {
        $coveragePackages = [];
    }

    sort($coveragePackages);

    if ($coveragePackages !== $expectedPackages) {
        $fail(
            'family',
            "{$coverageJob} CI matrix does not contain exactly every package",
        );
    }
}

$standalonePackages = $workflowConfiguration['jobs']['standalone-consumers']['strategy']['matrix']['package'] ?? [];

if (! is_array($standalonePackages)) {
    $standalonePackages = [];
}

sort($standalonePackages);

if ($standalonePackages !== $expectedPackages) {
    $fail('family', 'standalone-consumer CI matrix does not contain exactly every package');
}

$manifestPath = "{$root}/resources/js/types/generated.manifest.json";
$generatedManifest = is_file($manifestPath)
    ? json_decode((string) file_get_contents($manifestPath), true)
    : null;
$expectedGeneratedPackages = array_values(array_filter(
    $packages,
    static fn (string $package): bool => $package === 'data'
        || in_array('data', $internalDependencies[$package], true),
));
sort($expectedGeneratedPackages);
$generatedPackages = [];

if (is_array($generatedManifest) && is_array($generatedManifest['packages'] ?? null)) {
    $generatedPackages = array_map(
        static fn (string $package): string => substr($package, 4),
        array_values(array_filter(
            array_keys($generatedManifest['packages']),
            static fn (mixed $package): bool => is_string($package)
                && str_starts_with($package, 'nvl/'),
        )),
    );
}
sort($generatedPackages);

if ($generatedPackages !== $expectedGeneratedPackages) {
    $fail('family', 'generated TypeScript manifest does not cover exactly every Data-backed package');
}

foreach ($packages as $package) {
    $path = "{$root}/packages/nvl/{$package}";
    $packageSource = selfReadTree("{$path}/src");

    if (str_contains($packageSource, 'publishesMigrations(')) {
        $fail(
            $package,
            'migration publication must preserve package migration names when migrations also auto-load',
        );
    }

    foreach ($requiredFiles as $requiredFile) {
        if (! is_file("{$path}/{$requiredFile}")) {
            $fail($package, "missing required distribution file [{$requiredFile}]");
        }
    }

    $manifestPath = "{$path}/composer.json";
    $manifest = is_file($manifestPath)
        ? json_decode((string) file_get_contents($manifestPath), true)
        : null;

    if (! is_array($manifest)) {
        $fail($package, 'composer.json is not valid JSON');

        continue;
    }

    if (($manifest['name'] ?? null) !== "nvl/{$package}") {
        $fail($package, 'Composer package name does not match its directory');
    }

    $requirements = is_array($manifest['require'] ?? null) ? $manifest['require'] : [];

    if (($requirements['php'] ?? null) !== '^8.3') {
        $fail($package, 'PHP constraint must be ^8.3');
    }

    if (($requirements['laravel/framework'] ?? null) !== '^12.0 || ^13.0') {
        $fail($package, 'Laravel constraint must be ^12.0 || ^13.0');
    }

    $actualInternal = [];
    foreach ($requirements as $dependency => $constraint) {
        if (is_string($dependency) && str_starts_with($dependency, 'nvl/')) {
            $actualInternal[] = substr($dependency, 4);

            if ($constraint !== '^1.0') {
                $fail($package, "internal dependency [{$dependency}] must use ^1.0");
            }
        }
    }
    sort($actualInternal);
    $expectedInternal = $internalDependencies[$package];
    sort($expectedInternal);

    if ($actualInternal !== $expectedInternal) {
        $fail(
            $package,
            'internal dependencies are ['.implode(', ', $actualInternal).
            '], expected ['.implode(', ', $expectedInternal).']',
        );
    }

    $phpstan = is_file("{$path}/phpstan.neon.dist")
        ? (string) file_get_contents("{$path}/phpstan.neon.dist")
        : '';
    if (! str_contains($phpstan, 'level: max')) {
        $fail($package, 'PHPStan must run at level max');
    }
    if (preg_match('/baseline/i', $phpstan) === 1) {
        $fail($package, 'PHPStan baselines are not allowed');
    }

    $readme = is_file("{$path}/README.md") ? (string) file_get_contents("{$path}/README.md") : '';
    if (strlen($readme) < 1_500) {
        $fail($package, 'README is not self-contained enough for distribution');
    }
    $headingGroups = [
        'Purpose' => '/^## .*Purpose.*$/mi',
        'Requirements/installation' => '/^## .*(?:Requirements|Install).*$/mi',
        'Development/verification' => '/^## .*(?:Development|Quality|Verification).*$/mi',
        'License' => '/^## .*License.*$/mi',
    ];
    foreach ($headingGroups as $label => $pattern) {
        if (! preg_match($pattern, $readme)) {
            $fail($package, "README is missing a [{$label}] section");
        }
    }

    $skillDirectory = "{$path}/resources/boost/skills/nvl-{$package}";
    $skillPath = "{$skillDirectory}/SKILL.md";
    $metadataPath = "{$skillDirectory}/agents/openai.yaml";
    if (! is_file($skillPath) || ! is_file($metadataPath)) {
        $fail($package, 'standard nvl-prefixed skill and agent metadata are required');
    } else {
        $skill = (string) file_get_contents($skillPath);
        $metadata = (string) file_get_contents($metadataPath);
        if (! preg_match('/^---\\s*\\Rname:\\s*nvl-'.preg_quote($package, '/').'\\s*$/m', $skill)) {
            $fail($package, 'SKILL.md frontmatter name must match the package');
        }

        $metadataPattern = '/\Ainterface:\R {2}display_name: "([^"\r\n]+)"\R'
            .' {2}short_description: "([^"\r\n]+)"\R'
            .' {2}default_prompt: "([^"\r\n]+)"\R?\z/';
        if (preg_match($metadataPattern, $metadata, $agentMetadata) !== 1) {
            $fail($package, 'skill agent metadata must use the standardized interface schema');
        } else {
            $shortDescriptionLength = strlen($agentMetadata[2]);
            if ($shortDescriptionLength < 25 || $shortDescriptionLength > 64) {
                $fail($package, 'skill short_description must contain 25 to 64 characters');
            }

            if (! str_contains($agentMetadata[3], '$nvl-'.$package)) {
                $fail($package, "skill default_prompt must invoke [\$nvl-{$package}]");
            }
        }

        preg_match_all('/`([A-Z][A-Za-z0-9_]+)`/', $skill, $documentedSymbols);
        foreach (array_unique($documentedSymbols[1] ?? []) as $symbol) {
            if (selfIsConstantIdentifier($symbol)) {
                continue;
            }

            if (! isset($knownSymbols[$symbol])) {
                $fail($package, "skill references nonexistent symbol [{$symbol}]");
            }
        }
        preg_match_all('/`(nvl:[a-z0-9:_-]+)/', $skill, $skillCommands);
        foreach (array_unique($skillCommands[1] ?? []) as $command) {
            if (! str_contains($familySource, "'{$command}")
                && ! str_contains($familySource, "\"{$command}")) {
                $fail($package, "skill references nonexistent command [{$command}]");
            }
        }
    }

    $skillDirectories = glob("{$path}/resources/boost/skills/*", GLOB_ONLYDIR) ?: [];
    if (count($skillDirectories) !== 1 || basename($skillDirectories[0]) !== "nvl-{$package}") {
        $fail($package, 'exactly one standardized packaged skill is allowed');
    }

    if (selfReadTree("{$path}/skills") !== '') {
        $fail($package, 'top-level historical skill directories must not ship');
    }

    $providerSource = selfReadTree("{$path}/src/Providers");
    preg_match_all(
        "/'([a-z0-9-]+-(?:config|migrations|skills|translations|views))'/",
        $providerSource,
        $publishTagMatches,
    );
    $publishTags = array_values(array_unique($publishTagMatches[1] ?? []));

    foreach ($publishTags as $publishTag) {
        if (! str_contains($readme, "--tag={$publishTag}")) {
            $fail($package, "README does not document publish tag [{$publishTag}]");
        }
    }

    if (str_contains($packageSource, "nvl:{$package}:doctor")
        && (! str_contains($packageSource, '{--strict')
            || ! str_contains($packageSource, '{--format=text'))) {
        $fail($package, 'doctor command must expose the standard --strict and --format=text options');
    }

    if (in_array($package, $stateful, true)) {
        $configFiles = glob("{$path}/config/*.php") ?: [];
        $config = implode("\n", array_map(
            static fn (string $file): string => (string) file_get_contents($file),
            $configFiles,
        ));
        if (! str_contains($config, "'migrations'") || ! str_contains($config, "'enabled' => true")) {
            $fail($package, 'stateful package must expose migrations.enabled=true');
        }
        if (! is_dir("{$path}/database/migrations")) {
            $fail($package, 'stateful package has no migrations directory');
        }

        if (! str_contains($packageSource, "nvl:{$package}:doctor")) {
            $fail($package, 'stateful package must expose its doctor command');
        }
        if (! in_array("{$package}-migrations", $publishTags, true)) {
            $fail($package, 'stateful package must expose its migrations publish tag');
        }
    }

    if ($package === 'data' || in_array('data', $internalDependencies[$package], true)) {
        if ($package !== 'data'
            && ! str_contains(
                $providerSource,
                "\$typeScriptSources->register(__DIR__.'/..', 'nvl/{$package}')",
            )) {
            $fail($package, 'Data-backed package must register its TypeScript source directory');
        }
    }

    foreach (selfFiles("{$path}/src/Data", 'php') as $dataFile) {
        $contents = (string) file_get_contents($dataFile);
        $relative = substr($dataFile, strlen($path) + 1);
        $forbiddenDataBoundaries = [
            '/Illuminate\\\\Http\\\\Request/' => 'HTTP Request dependency',
            '/\brequest\s*\(/' => 'request helper access',
            '/\bDB::/' => 'database facade access',
            '/\bRoute::/' => 'route facade access',
            '/::query\s*\(/' => 'Eloquent query access',
        ];

        foreach ($forbiddenDataBoundaries as $pattern => $description) {
            if (preg_match($pattern, $contents) === 1) {
                $fail($package, "Data class [{$relative}] contains {$description}");
            }
        }
    }

    foreach (selfFiles("{$path}/src", 'php') as $sourceFile) {
        $contents = (string) file_get_contents($sourceFile);
        $relative = substr($sourceFile, strlen($path) + 1);

        if (preg_match('/\bapp\s*\(/', $contents) === 1
            && ! in_array(
                $relative,
                $serviceLocatorAllowlist[$package] ?? [],
                true,
            )) {
            $fail($package, "service-locator access is not allowed in [{$relative}]");
        }

        if (preg_match('/protected\s+\$guarded\s*=\s*\[\s*\]/', $contents) === 1) {
            $fail($package, "unguarded mass assignment is not allowed in [{$relative}]");
        }

        if (preg_match(
            '/@deprecated|Backwards?-compatible wrapper|compatibility alias|legacy alias/i',
            $contents,
        ) === 1) {
            $fail($package, "deprecated pre-v1 API language found in [{$relative}]");
        }

        if (str_starts_with($relative, 'src/Actions/')) {
            if (preg_match('/\bfinal\s+(?:readonly\s+)?class\s+/', $contents) !== 1) {
                $fail($package, "Action [{$relative}] must be final");
            }

            preg_match(
                '/\/\*\*(?<doc>[\s\S]*?)\*\/\s*final\s+(?:readonly\s+)?class\s+/',
                $contents,
                $actionClassDocumentation,
            );
            if (preg_match(
                '/\b[A-Za-z_][A-Za-z0-9_]*Action\s+\$[A-Za-z_][A-Za-z0-9_]*/',
                $contents,
            ) === 1
                && preg_match(
                    '/orchestrat|composition|pipeline|workflow|delegat|canonical[^\r\n]*Actions?/i',
                    $actionClassDocumentation['doc'] ?? '',
                ) !== 1) {
                $fail(
                    $package,
                    "Action [{$relative}] composes another Action without documenting approved orchestration",
                );
            }

            preg_match_all(
                '/\bpublic\s+(?:static\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/',
                $contents,
                $publicMethods,
            );
            $methodNames = $publicMethods[1] ?? [];
            $unexpectedMethods = array_values(array_diff(
                $methodNames,
                ['__construct', 'execute'],
            ));

            if (count(array_keys($methodNames, 'execute', true)) !== 1) {
                $fail($package, "Action [{$relative}] must expose exactly one public execute method");
            }

            if ($unexpectedMethods !== []) {
                $fail(
                    $package,
                    "Action [{$relative}] exposes unsupported public methods: "
                        .implode(', ', $unexpectedMethods),
                );
            }
        }
    }

    foreach (selfFiles("{$path}/database/migrations", 'php') as $migrationFile) {
        if (preg_match(
            '/_(?:add|alter|backfill|correct|fix|update)_/',
            basename($migrationFile),
        ) === 1) {
            $fail(
                $package,
                'unpublished package migrations must be consolidated; corrective migration found ['.
                basename($migrationFile).']',
            );
        }
    }

    if (isset($managementConfiguration[$package])) {
        [$configFile, $disabledDeclaration] = $managementConfiguration[$package];
        $configPath = "{$path}/config/{$configFile}";
        $config = is_file($configPath) ? (string) file_get_contents($configPath) : '';
        if (! str_contains($config, $disabledDeclaration)) {
            $fail($package, 'management route configuration must default to disabled');
        }
    }

    $scannableFiles = [];
    foreach ($scanRoots as $scanRoot) {
        $candidate = "{$path}/{$scanRoot}";
        if (is_file($candidate)) {
            $scannableFiles[] = $candidate;
        } elseif (is_dir($candidate)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $candidate,
                FilesystemIterator::SKIP_DOTS,
            ));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $scannableFiles[] = $file->getPathname();
                }
            }
        }
    }

    foreach ($scannableFiles as $file) {
        $contents = (string) file_get_contents($file);
        $relative = substr($file, strlen($path) + 1);
        $forbidden = [
            '/Gift\\s*Come\\s*True|giftcometrue/i' => 'Gift-specific language',
            '/\\bApp\\\\\\\\/' => 'host App namespace',
            '/\\bModules(?:\\\\\\\\|\\.)/' => 'historical Modules namespace',
            '/\\bShopify\\b/i' => 'Shopify domain',
            '/\\bvouchers?\\b/i' => 'voucher domain',
            '/\\bvendors\\b/i' => 'vendor-owner domain',
            '/\\binquiries?\\b/i' => 'inquiry domain',
            '/booking[ _-]?logs?/i' => 'booking-log domain',
            '/\\bproducts\\b|Product::class|product\\./i' => 'catalog consumer domain',
            '/php artisan (?:typescript:transform|translatable:gather|settings:|translations:|metafields:)/' => 'removed command name',
        ];

        foreach ($forbidden as $pattern => $description) {
            if (preg_match($pattern, $contents) === 1) {
                $fail($package, "{$description} found in [{$relative}]");
            }
        }

        if (str_ends_with($file, '.php')
            && ! str_ends_with($file, '.blade.php')
            && ! str_contains($contents, 'declare(strict_types=1);')) {
            $fail($package, "PHP file [{$relative}] does not declare strict types");
        }
    }

    preg_match_all('/php artisan ([a-z0-9:_-]+)/i', $readme, $documentedCommands);
    $frameworkCommands = [
        'config:cache',
        'make:queue-batches-table',
        'migrate',
        'queue:work',
        'route:cache',
        'vendor:publish',
    ];
    foreach (array_unique($documentedCommands[1] ?? []) as $command) {
        if (in_array($command, $frameworkCommands, true)) {
            continue;
        }
        if (! str_contains($familySource, "'{$command}") && ! str_contains($familySource, "\"{$command}")) {
            $fail($package, "README documents nonexistent command [{$command}]");
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "NVL package-family validation failed:\n\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    fwrite(STDERR, "\n".count($errors)." violation(s) found.\n");

    exit(1);
}

fwrite(STDOUT, 'Validated '.count($packages)." NVL package distributions.\n");

/**
 * Read every file below a directory into one static-analysis string.
 */
function selfReadTree(string $directory): string
{
    if (! is_dir($directory)) {
        return '';
    }

    $contents = '';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $directory,
        FilesystemIterator::SKIP_DOTS,
    ));

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $contents .= "\n".file_get_contents($file->getPathname());
        }
    }

    return $contents;
}

/**
 * Return sorted files below a directory, optionally constrained by extension.
 *
 * @return list<string>
 */
function selfFiles(string $directory, ?string $extension = null): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $directory,
        FilesystemIterator::SKIP_DOTS,
    ));

    foreach ($iterator as $file) {
        if ($file->isFile()
            && ($extension === null || $file->getExtension() === $extension)) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * Determine whether a documented PHP identifier uses conventional constant syntax.
 */
function selfIsConstantIdentifier(string $symbol): bool
{
    return preg_match('/^[A-Z][A-Z0-9_]*$/D', $symbol) === 1;
}
