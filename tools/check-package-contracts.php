<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Nvl\Suite\SuiteServiceProvider;

require dirname(__DIR__).'/vendor/autoload.php';

const CONTRACT_BASELINE_PATH = __DIR__.'/package-contracts.json';
const CONTRACT_SCHEMA_VERSION = 2;

/**
 * Normalize one reflection type into a deterministic source-like signature.
 */
function contractType(?ReflectionType $type): string
{
    if ($type === null) {
        return 'mixed';
    }

    if ($type instanceof ReflectionNamedType) {
        $name = $type->getName();

        return $type->allowsNull() && $name !== 'mixed' && $name !== 'null'
            ? "?{$name}"
            : $name;
    }

    if ($type instanceof ReflectionUnionType) {
        return implode('|', array_map(contractType(...), $type->getTypes()));
    }

    if ($type instanceof ReflectionIntersectionType) {
        return implode('&', array_map(contractType(...), $type->getTypes()));
    }

    return (string) $type;
}

/**
 * Normalize a reflected default or attribute value without serializing objects.
 */
function contractValue(mixed $value): string
{
    if ($value instanceof BackedEnum) {
        return $value::class.'::'.$value->name.'='.$value->value;
    }

    if ($value instanceof UnitEnum) {
        return $value::class.'::'.$value->name;
    }

    if (is_object($value)) {
        return $value::class;
    }

    if (is_resource($value)) {
        return get_resource_type($value);
    }

    return (string) json_encode(
        $value,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
}

/**
 * Return contract-bearing PHPDoc annotations while ignoring prose-only edits.
 *
 * @return list<string>
 */
function contractAnnotations(string|false $documentation): array
{
    if (! is_string($documentation)) {
        return [];
    }

    preg_match_all(
        '/^\s*\*\s*(?<annotation>@(?:extends|implements|method|mixin|param|property(?:-read|-write)?|return|template(?:-contravariant|-covariant)?|throws)\b[^\r\n]*)/m',
        $documentation,
        $matches,
    );

    return array_map(
        static fn (string $annotation): string => preg_replace('/\s+/', ' ', trim($annotation))
            ?? trim($annotation),
        $matches['annotation'],
    );
}

/**
 * Return deterministic attribute declarations for a reflected API member.
 *
 * @param  list<ReflectionAttribute<object>>  $attributes
 * @return list<string>
 */
function contractAttributes(array $attributes): array
{
    $signatures = array_map(
        static fn (ReflectionAttribute $attribute): string => sprintf(
            '#[%s(%s)]',
            $attribute->getName(),
            implode(', ', array_map(
                static fn (mixed $argument): string => contractValue($argument),
                $attribute->getArguments(),
            )),
        ),
        $attributes,
    );
    sort($signatures);

    return $signatures;
}

/**
 * Format one reflected method parameter.
 */
function contractParameter(ReflectionParameter $parameter): string
{
    $signature = implode(' ', contractAttributes($parameter->getAttributes()));
    $signature = $signature === '' ? '' : "{$signature} ";
    $signature .= contractType($parameter->getType()).' ';

    if ($parameter->isPassedByReference()) {
        $signature .= '&';
    }

    if ($parameter->isVariadic()) {
        $signature .= '...';
    }

    $signature .= '$'.$parameter->getName();

    if ($parameter->isDefaultValueAvailable() && ! $parameter->isVariadic()) {
        $default = $parameter->isDefaultValueConstant()
            ? (string) $parameter->getDefaultValueConstantName()
            : contractValue($parameter->getDefaultValue());
        $signature .= "={$default}";
    }

    return $signature;
}

/**
 * Format method visibility and modifiers.
 */
function contractMethodModifiers(ReflectionMethod $method): string
{
    $modifiers = [$method->isPublic() ? 'public' : 'protected'];

    if ($method->isAbstract()) {
        $modifiers[] = 'abstract';
    }

    if ($method->isFinal()) {
        $modifiers[] = 'final';
    }

    if ($method->isStatic()) {
        $modifiers[] = 'static';
    }

    return implode(' ', $modifiers);
}

/**
 * Format one declared method and its type-bearing documentation.
 *
 * @return list<string>
 */
function contractMethod(ReflectionMethod $method): array
{
    $signature = sprintf(
        '%s function %s(%s): %s',
        contractMethodModifiers($method),
        $method->getName(),
        implode(', ', array_map(contractParameter(...), $method->getParameters())),
        contractType($method->getReturnType()),
    );

    return [
        ...contractAttributes($method->getAttributes()),
        $signature,
        ...contractAnnotations($method->getDocComment()),
    ];
}

/**
 * Format one declared property.
 *
 * @param  ReflectionClass<object>  $class
 */
function contractProperty(
    ReflectionClass $class,
    ReflectionProperty $property,
): string {
    $modifiers = [$property->isPublic() ? 'public' : 'protected'];

    if ($property->isStatic()) {
        $modifiers[] = 'static';
    }

    if ($property->isReadOnly()) {
        $modifiers[] = 'readonly';
    }

    $signature = sprintf(
        '%s %s $%s',
        implode(' ', $modifiers),
        contractType($property->getType()),
        $property->getName(),
    );
    $defaults = $class->getDefaultProperties();

    if (array_key_exists($property->getName(), $defaults)) {
        $signature .= '='.contractValue($defaults[$property->getName()]);
    }

    return $signature;
}

/**
 * Format one declared class constant.
 */
function contractConstant(ReflectionClassConstant $constant): string
{
    $modifiers = [$constant->isPublic() ? 'public' : 'protected'];

    if ($constant->isFinal()) {
        $modifiers[] = 'final';
    }

    return sprintf(
        '%s const %s %s=%s',
        implode(' ', $modifiers),
        contractType($constant->getType()),
        $constant->getName(),
        contractValue($constant->getValue()),
    );
}

/**
 * Return the declaration header for one public package symbol.
 *
 * @param  ReflectionClass<object>  $class
 */
function contractClassHeader(ReflectionClass $class): string
{
    $modifiers = [];

    if ($class->isInterface()) {
        $kind = 'interface';
    } elseif ($class->isTrait()) {
        $kind = 'trait';
    } elseif ($class->isEnum()) {
        $kind = 'enum';
    } else {
        $kind = 'class';

        if ($class->isAbstract()) {
            $modifiers[] = 'abstract';
        }

        if ($class->isFinal()) {
            $modifiers[] = 'final';
        }

        if ($class->isReadOnly()) {
            $modifiers[] = 'readonly';
        }
    }

    $header = trim(implode(' ', [...$modifiers, $kind, $class->getName()]));
    $parent = $class->getParentClass();

    if ($parent instanceof ReflectionClass) {
        $header .= ' extends '.$parent->getName();
    }

    $interfaces = $class->getInterfaceNames();

    if ($parent instanceof ReflectionClass) {
        $interfaces = array_values(array_diff($interfaces, $parent->getInterfaceNames()));
    }

    sort($interfaces);

    if ($interfaces !== []) {
        $header .= ($class->isInterface() ? ' extends ' : ' implements ')
            .implode(', ', $interfaces);
    }

    if ($class->isEnum()) {
        $enumClass = $class->getName();

        if (! enum_exists($enumClass)) {
            throw new LogicException("Reflected enum [{$enumClass}] is not loadable.");
        }

        $enum = new ReflectionEnum($enumClass);
        $backingType = $enum->getBackingType();

        if ($backingType instanceof ReflectionNamedType) {
            $header .= ': '.$backingType->getName();
        }
    }

    return $header;
}

/**
 * Build the public and extension API signature for one reflected symbol.
 *
 * @param  ReflectionClass<object>  $class
 * @return list<string>
 */
function contractClass(ReflectionClass $class): array
{
    $contract = [
        ...contractAttributes($class->getAttributes()),
        contractClassHeader($class),
        ...contractAnnotations($class->getDocComment()),
    ];

    foreach ($class->getReflectionConstants() as $constant) {
        if ($constant->getDeclaringClass()->getName() !== $class->getName()
            || (! $constant->isPublic() && ! $constant->isProtected())) {
            continue;
        }

        $contract[] = contractConstant($constant);
    }

    foreach ($class->getProperties() as $property) {
        if ($property->getDeclaringClass()->getName() !== $class->getName()
            || (! $property->isPublic() && ! $property->isProtected())) {
            continue;
        }

        $contract = [
            ...$contract,
            ...contractAttributes($property->getAttributes()),
            contractProperty($class, $property),
            ...contractAnnotations($property->getDocComment()),
        ];
    }

    foreach ($class->getMethods() as $method) {
        if ($method->getDeclaringClass()->getName() !== $class->getName()
            || (! $method->isPublic() && ! $method->isProtected())) {
            continue;
        }

        $contract = [...$contract, ...contractMethod($method)];
    }

    return $contract;
}

/**
 * Return every PHP file below a directory in deterministic order.
 *
 * @return list<string>
 */
function contractPhpFiles(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo) {
            continue;
        }

        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * Hash executable PHP tokens while ignoring formatting and comments.
 */
function contractPhpHash(string $path): string
{
    $contents = (string) file_get_contents($path);
    $normalized = '';

    foreach (token_get_all($contents) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                continue;
            }

            $normalized .= $token[1];

            continue;
        }

        $normalized .= $token;
    }

    return hash('sha256', $normalized);
}

/**
 * Hash a contract-bearing package directory by relative file path.
 *
 * @return array<string, string>
 */
function contractDirectoryHashes(string $packagePath, string $directory): array
{
    $hashes = [];

    foreach (contractPhpFiles("{$packagePath}/{$directory}") as $file) {
        $relative = substr($file, strlen($packagePath) + 1);
        $hashes[$relative] = contractPhpHash($file);
    }

    return $hashes;
}

/**
 * Resolve one Composer PSR-4 class name from a package file.
 */
function contractClassName(string $prefix, string $basePath, string $file): string
{
    $relative = substr($file, strlen($basePath) + 1, -4);

    return $prefix.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
}

/**
 * Reflect a class, interface, trait, or enum without guessing its symbol kind.
 *
 * @return ReflectionClass<object>|null
 */
function contractReflection(string $className): ?ReflectionClass
{
    if (class_exists($className)
        || interface_exists($className)
        || trait_exists($className)
        || enum_exists($className)) {
        return new ReflectionClass($className);
    }

    return null;
}

/**
 * Return package publish tags declared by service providers.
 *
 * @return list<string>
 */
function contractPublishTags(string $packagePath): array
{
    $source = '';

    foreach (contractPhpFiles("{$packagePath}/src") as $provider) {
        $source .= "\n".(string) file_get_contents($provider);
    }

    preg_match_all(
        "/'([a-z0-9-]+-(?:adoption|config|migrations|skills|tooling|translations|views))'/",
        $source,
        $matches,
    );
    $tags = array_values(array_unique($matches[1]));
    sort($tags);

    return $tags;
}

/**
 * Build the suite-level distribution contract.
 *
 * @return array<string, mixed>
 */
function contractSuite(string $root): array
{
    return [
        'providers' => [SuiteServiceProvider::class],
        'publish_tags' => contractPublishTags($root),
        'configuration' => [
            'config/nvl-suite.php' => contractPhpHash("{$root}/config/nvl-suite.php"),
        ],
    ];
}

/**
 * Decode a JSON object and preserve only string-keyed top-level fields.
 *
 * @return array<string, mixed>
 */
function contractJsonObject(string $path): array
{
    $decoded = json_decode(
        (string) file_get_contents($path),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    if (! is_array($decoded)) {
        throw new RuntimeException("JSON document [{$path}] must contain an object.");
    }

    $object = [];

    foreach ($decoded as $key => $value) {
        if (! is_string($key)) {
            throw new RuntimeException("JSON document [{$path}] must use string top-level keys.");
        }

        $object[$key] = $value;
    }

    return $object;
}

/**
 * Normalize a mixed value into a validated string-keyed map.
 *
 * @return array<string, mixed>
 */
function contractStringMap(mixed $value, string $description): array
{
    if (! is_array($value)) {
        return [];
    }

    $map = [];

    foreach ($value as $key => $item) {
        if (! is_string($key)) {
            throw new RuntimeException("{$description} must use string keys.");
        }

        $map[$key] = $item;
    }

    return $map;
}

/**
 * Return the validated canonical package list.
 *
 * @return list<string>
 */
function contractFamilyPackages(string $root): array
{
    $family = require "{$root}/tools/package-family.php";

    if (! is_array($family) || ! is_array($family['packages'] ?? null)) {
        throw new RuntimeException('Package family catalog must define a packages array.');
    }

    $packages = [];

    foreach ($family['packages'] as $package) {
        if (! is_string($package)) {
            throw new RuntimeException('Package family catalog entries must be strings.');
        }

        $packages[] = $package;
    }

    return $packages;
}

/**
 * Build one package's deterministic compatibility contract.
 *
 * @return array<string, mixed>
 */
function contractPackage(string $root, string $package): array
{
    $packagePath = "{$root}/packages/nvl/{$package}";
    $manifest = contractJsonObject("{$packagePath}/composer.json");
    $autoload = contractStringMap(
        $manifest['autoload'] ?? null,
        "Composer autoload configuration for nvl/{$package}",
    );
    $autoloadFiles = [];

    foreach (is_array($autoload['files'] ?? null) ? $autoload['files'] : [] as $file) {
        if (is_string($file)) {
            $path = "{$packagePath}/{$file}";
            $autoloadFiles[$file] = contractPhpHash($path);
        }
    }

    $autoloadFilePaths = array_map(
        static fn (string $file): string => (string) realpath("{$packagePath}/{$file}"),
        array_keys($autoloadFiles),
    );
    $symbols = [];
    $commands = [];

    foreach (is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [] as $prefix => $relative) {
        if (! is_string($prefix) || ! is_string($relative)) {
            continue;
        }

        $basePath = "{$packagePath}/".rtrim($relative, '/');

        foreach (contractPhpFiles($basePath) as $file) {
            if (in_array((string) realpath($file), $autoloadFilePaths, true)) {
                continue;
            }

            $className = contractClassName($prefix, $basePath, $file);
            $reflection = contractReflection($className);

            if (! $reflection instanceof ReflectionClass) {
                throw new RuntimeException(
                    "Autoloaded PHP file [{$file}] does not declare expected symbol [{$className}].",
                );
            }

            $symbols[$className] = contractClass($reflection);

            if ($reflection->isSubclassOf(Command::class)) {
                $signature = $reflection->getDefaultProperties()['signature'] ?? null;

                if (is_string($signature)) {
                    $commands[$className] = preg_replace('/\s+/', ' ', trim($signature))
                        ?? trim($signature);
                }
            }
        }
    }

    ksort($autoloadFiles);
    ksort($symbols);
    ksort($commands);
    $extra = contractStringMap(
        $manifest['extra'] ?? null,
        "Composer extra configuration for nvl/{$package}",
    );
    $laravel = contractStringMap(
        $extra['laravel'] ?? null,
        "Composer Laravel configuration for nvl/{$package}",
    );
    $providers = $laravel['providers'] ?? [];
    $providers = is_array($providers)
        ? array_values(array_filter($providers, 'is_string'))
        : [];
    sort($providers);

    return [
        'providers' => $providers,
        'autoload_files' => $autoloadFiles,
        'publish_tags' => contractPublishTags($packagePath),
        'commands' => $commands,
        'symbols' => $symbols,
        'configuration' => contractDirectoryHashes($packagePath, 'config'),
        'routes' => contractDirectoryHashes($packagePath, 'routes'),
        'migrations' => contractDirectoryHashes($packagePath, 'database/migrations'),
    ];
}

/**
 * Build the complete package-family contract snapshot.
 *
 * @return array{schema_version: int, suite: array<string, mixed>, packages: array<string, array<string, mixed>>}
 */
function contractSnapshot(string $root): array
{
    $packages = [];

    foreach (contractFamilyPackages($root) as $package) {
        $packages[$package] = contractPackage($root, $package);
    }

    ksort($packages);

    return [
        'schema_version' => CONTRACT_SCHEMA_VERSION,
        'suite' => contractSuite($root),
        'packages' => $packages,
    ];
}

/**
 * Encode the snapshot consistently for comparison and review.
 *
 * @param  array<string, mixed>  $snapshot
 */
function contractJson(array $snapshot): string
{
    return (string) json_encode(
        $snapshot,
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    )."\n";
}

$root = dirname(__DIR__);
$snapshot = contractSnapshot($root);
$encoded = contractJson($snapshot);
$update = in_array('--update', $argv, true);

if ($update) {
    file_put_contents(CONTRACT_BASELINE_PATH, $encoded);
    fwrite(
        STDOUT,
        sprintf("Updated public contracts for %d NVL packages.\n", count($snapshot['packages'])),
    );

    exit(0);
}

if (! is_file(CONTRACT_BASELINE_PATH)) {
    fwrite(
        STDERR,
        "Package contract baseline is missing. Run [composer contracts:update].\n",
    );

    exit(1);
}

$baseline = contractJsonObject(CONTRACT_BASELINE_PATH);

if ($baseline === $snapshot) {
    fwrite(
        STDOUT,
        sprintf("Public contracts for %d NVL packages are unchanged.\n", count($snapshot['packages'])),
    );

    exit(0);
}

$changed = [];
$baselineSuite = contractStringMap($baseline['suite'] ?? null, 'Suite contract baseline');

if ($baselineSuite !== $snapshot['suite']) {
    $changed[] = 'nvl/laravel-suite';
}

$baselinePackages = contractStringMap(
    $baseline['packages'] ?? null,
    'Package contract baseline',
);

foreach (array_unique([...array_keys($baselinePackages), ...array_keys($snapshot['packages'])]) as $package) {
    if (($baselinePackages[$package] ?? null) !== ($snapshot['packages'][$package] ?? null)) {
        $changed[] = "nvl/{$package}";
    }
}

fwrite(
    STDERR,
    'Public package contracts changed without an acknowledged baseline update: '
    .implode(', ', $changed).".\n"
    ."Review compatibility, then run [composer contracts:update] for intentional changes.\n",
);

exit(1);
