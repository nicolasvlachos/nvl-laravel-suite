<?php

declare(strict_types=1);

namespace Nvl\Suite\Services\ConsumerAudit;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Filesystem\Filesystem;
use Nvl\Suite\Support\ConsumerAuditFinding;
use PhpToken;
use ReflectionMethod;
use ReflectionNamedType;
use SplFileInfo;
use Throwable;

/**
 * Scans application-owned PHP for unsupported package persistence access.
 *
 * @phpstan-type ModelReference array{class: string, package: string, kind: 'model'|'builder'|'collection'}
 * @phpstan-type VariableModels array<int, array<string, ModelReference>>
 */
final readonly class PhpConsumerBoundaryScanner
{
    /** @var list<string> */
    private const array WRITE_METHODS = [
        'attach',
        'create',
        'createMany',
        'createManyQuietly',
        'createOrFirst',
        'createQuietly',
        'decrement',
        'decrementEach',
        'delete',
        'deleteOrFail',
        'deleteQuietly',
        'destroy',
        'detach',
        'forceCreate',
        'forceCreateMany',
        'forceCreateManyQuietly',
        'forceCreateQuietly',
        'forceDelete',
        'forceDeleteQuietly',
        'forceDestroy',
        'firstOrCreate',
        'increment',
        'incrementEach',
        'incrementOrCreate',
        'insert',
        'insertGetId',
        'insertOrIgnore',
        'insertOrIgnoreReturning',
        'insertOrIgnoreUsing',
        'insertUsing',
        'push',
        'pushQuietly',
        'restore',
        'restoreQuietly',
        'save',
        'saveMany',
        'saveManyQuietly',
        'saveOrFail',
        'saveOrIgnore',
        'saveQuietly',
        'sync',
        'syncWithPivotValues',
        'syncWithoutDetaching',
        'toggle',
        'touch',
        'touchQuietly',
        'truncate',
        'update',
        'updateExistingPivot',
        'updateFrom',
        'updateOrFail',
        'updateOrInsert',
        'updateOrCreate',
        'updateQuietly',
        'upsert',
    ];

    /** @var list<string> */
    private const array RAW_TABLE_WRITE_METHODS = [
        'decrement',
        'decrementEach',
        'delete',
        'increment',
        'incrementEach',
        'insert',
        'insertGetId',
        'insertOrIgnore',
        'insertOrIgnoreReturning',
        'insertOrIgnoreUsing',
        'insertUsing',
        'truncate',
        'update',
        'updateFrom',
        'updateOrInsert',
        'upsert',
    ];

    /** @var list<string> */
    private const array ALLOWED_IDENTITY_METHODS = [
        'getKey',
        'getKeyName',
        'getMorphClass',
        'getRouteKey',
        'getRouteKeyName',
        'is',
        'isNot',
        'relationLoaded',
    ];

    /** @var list<string> */
    private const array INSTANCE_QUERY_METHODS = [
        'fresh',
        'load',
        'loadAggregate',
        'loadAvg',
        'loadCount',
        'loadExists',
        'loadMax',
        'loadMin',
        'loadMissing',
        'loadMorph',
        'loadMorphCount',
        'loadSum',
        'refresh',
    ];

    /** @var list<string> */
    private const array MODEL_RESULT_METHODS = [
        'create',
        'createOrFirst',
        'createQuietly',
        'find',
        'findOrFail',
        'first',
        'firstOrFail',
        'firstOrNew',
        'firstOrCreate',
        'firstWhere',
        'sole',
        'forceCreate',
        'forceCreateQuietly',
        'updateOrCreate',
    ];

    /** @var list<string> */
    private const array NON_MODEL_RESULT_METHODS = [
        'all',
        'average',
        'avg',
        'count',
        'cursor',
        'doesntExist',
        'exists',
        'get',
        'getModels',
        'max',
        'min',
        'paginate',
        'pluck',
        'simplePaginate',
        'sum',
        'value',
    ];

    /** @var list<class-string> */
    private const array OWNER_TRAITS = [
        'Nvl\\Comments\\Traits\\InteractsWithComments',
        'Nvl\\Content\\Traits\\HasContent',
        'Nvl\\Media\\Traits\\InteractsWithMedia',
        'Nvl\\Metafields\\Traits\\HasMetafields',
        'Nvl\\Seo\\Traits\\HasSeo',
        'Nvl\\Taxonomy\\Concerns\\HasTaxonomies',
    ];

    /** @var list<string> */
    private const array EXCLUDED_SEGMENTS = [
        'vendor',
        'storage',
        'node_modules',
        '.git',
        'tests',
        'test',
        'factories',
        'fixtures',
    ];

    public function __construct(private Filesystem $filesystem) {}

    /**
     * @param  list<string>  $roots
     * @param  array<string, string>  $namespaceModules
     * @param  array<string, string>  $tableOwners
     * @param  list<string>  $enabledPackages
     * @return list<ConsumerAuditFinding>
     */
    public function scan(
        string $basePath,
        array $roots,
        array $namespaceModules,
        array $tableOwners,
        array $enabledPackages,
    ): array {
        $basePath = rtrim((string) realpath($basePath), DIRECTORY_SEPARATOR);
        $files = $this->phpFiles($basePath, $roots);
        $findings = [];

        foreach ($files as $path => $absolutePath) {
            $source = $this->filesystem->get($absolutePath);
            $imports = PhpImportMap::fromSource($source);

            foreach ($this->modelFindings($source, $path, $imports, $namespaceModules) as $finding) {
                $findings[] = $finding;
            }

            foreach ($this->tableFindings($source, $path, $tableOwners, $enabledPackages) as $finding) {
                $findings[] = $finding;
            }
        }

        return $this->sortedUnique($findings);
    }

    /**
     * @param  list<string>  $roots
     * @return array<string, string>
     */
    private function phpFiles(string $basePath, array $roots): array
    {
        $files = [];

        foreach ($roots as $root) {
            $candidates = is_file($root)
                ? [new SplFileInfo($root)]
                : $this->filesystem->allFiles($root, hidden: true);

            foreach ($candidates as $file) {
                $absolutePath = $file->getPathname();

                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relativePath = str_replace(
                    DIRECTORY_SEPARATOR,
                    '/',
                    ltrim(substr($absolutePath, strlen($basePath)), DIRECTORY_SEPARATOR),
                );

                if ($this->excluded($relativePath)) {
                    continue;
                }

                $files[$relativePath] = $absolutePath;
            }
        }

        ksort($files);

        return $files;
    }

    private function excluded(string $path): bool
    {
        $segments = explode('/', strtolower($path));

        if (in_array('bootstrap', $segments, true) && in_array('cache', $segments, true)) {
            return true;
        }

        foreach (self::EXCLUDED_SEGMENTS as $segment) {
            if (in_array($segment, $segments, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $namespaceModules
     * @return list<ConsumerAuditFinding>
     */
    private function modelFindings(
        string $source,
        string $path,
        PhpImportMap $imports,
        array $namespaceModules,
    ): array {
        $tokens = PhpToken::tokenize($source, TOKEN_PARSE);
        $scopeMap = $this->tokenScopes($tokens);
        $scopes = $scopeMap['tokens'];
        $scopeParents = $scopeMap['parents'];
        $arrowBodies = $scopeMap['arrow_bodies'];
        $variableModels = [];
        $variableReturnModels = [];
        $propertyReturnModels = $this->propertyPackageModelReturns(
            $tokens,
            $imports,
            $namespaceModules,
        );
        $findings = [];
        $adoptionMigration = preg_match('~(?:^|/)database/migrations/~', $path) === 1;

        foreach ($tokens as $index => $token) {
            $scope = $scopes[$index] ?? 0;

            if ($token->id === T_FN) {
                $parentScope = $scopeParents[$scope] ?? null;

                if ($parentScope !== null) {
                    $variableModels[$scope] = $variableModels[$parentScope] ?? [];
                    $variableReturnModels[$scope] = $variableReturnModels[$parentScope] ?? [];
                }

                continue;
            }

            if ($token->id === T_VARIABLE) {
                if (isset($arrowBodies[$scope]) && $index < $arrowBodies[$scope]) {
                    $variableModels = $this->withVariableModel(
                        $variableModels,
                        $scope,
                        $token->text,
                        null,
                    );
                    $variableReturnModels = $this->withVariableModel(
                        $variableReturnModels,
                        $scope,
                        $token->text,
                        null,
                    );
                }

                $assignment = $this->nextMeaningfulToken($tokens, $index + 1);

                if ($assignment !== null && $tokens[$assignment]->text === '=') {
                    $rightHandSide = $this->nextMeaningfulToken($tokens, $assignment + 1);
                    $model = $rightHandSide === null
                        ? null
                        : $this->assignedStaticPackageModel(
                            $tokens,
                            $rightHandSide,
                            $imports,
                            $namespaceModules,
                        );
                    $model ??= $rightHandSide === null
                        ? null
                        : $this->assignedNewPackageModel(
                            $tokens,
                            $rightHandSide,
                            $imports,
                            $namespaceModules,
                        );
                    $model ??= $rightHandSide === null
                        ? null
                        : $this->assignedPackageModelReturn(
                            $tokens,
                            $rightHandSide,
                            $variableReturnModels[$scope] ?? [],
                            $propertyReturnModels,
                        );

                    $variableModels = $this->withVariableModel(
                        $variableModels,
                        $scope,
                        $token->text,
                        $model,
                    );
                    $variableReturnModels = $this->withVariableModel(
                        $variableReturnModels,
                        $scope,
                        $token->text,
                        null,
                    );

                    continue;
                }

                if ($this->closureCaptureVariable($tokens, $index)) {
                    $parentScope = $scopeParents[$scope] ?? null;

                    if ($parentScope !== null) {
                        $variableModels = $this->withVariableModel(
                            $variableModels,
                            $scope,
                            $token->text,
                            $variableModels[$parentScope][$token->text] ?? null,
                        );
                        $variableReturnModels = $this->withVariableModel(
                            $variableReturnModels,
                            $scope,
                            $token->text,
                            $variableReturnModels[$parentScope][$token->text] ?? null,
                        );
                    }

                    continue;
                }

                $type = $this->previousMeaningfulToken($tokens, $index - 1);
                $typedModel = $type === null
                    ? null
                    : $this->resolvedPackageModel($tokens[$type], $imports, $namespaceModules);

                if ($typedModel !== null) {
                    $variableModels = $this->withVariableModel(
                        $variableModels,
                        $scope,
                        $token->text,
                        $typedModel,
                    );
                }

                $returnedModel = $type === null
                    ? null
                    : $this->resolvedPackageModelReturn(
                        $tokens[$type],
                        $imports,
                        $namespaceModules,
                    );

                if ($returnedModel !== null) {
                    $variableReturnModels = $this->withVariableModel(
                        $variableReturnModels,
                        $scope,
                        $token->text,
                        $returnedModel,
                    );
                }

                $model = $variableModels[$scope][$token->text] ?? null;
                $operator = $this->nextMeaningfulToken($tokens, $index + 1);

                if ($model === null || $operator === null || $tokens[$operator]->id !== T_OBJECT_OPERATOR) {
                    continue;
                }

                $writeMethod = $this->chainedWriteMethod($tokens, $operator);

                if ($writeMethod === null) {
                    $queryMethod = $model['kind'] === 'model'
                        ? $this->directInstanceQueryMethod(
                            $tokens,
                            $operator,
                            $model['class'],
                        )
                        : null;

                    if ($queryMethod !== null && ! $adoptionMigration) {
                        $findings[] = $this->modelFinding(
                            code: 'consumer.package_model_query',
                            package: $model['package'],
                            path: $path,
                            line: $queryMethod->line,
                            symbol: $model['class'].'::'.$queryMethod->text,
                        );
                    }

                    continue;
                }

                $findings[] = $this->modelFinding(
                    code: 'consumer.package_model_write',
                    package: $model['package'],
                    path: $path,
                    line: $writeMethod->line,
                    symbol: $model['class'].'::'.$writeMethod->text,
                );

                continue;
            }

            if ($token->id === T_NEW) {
                $classIndex = $this->nextMeaningfulToken($tokens, $index + 1);
                $model = $classIndex === null
                    ? null
                    : $this->resolvedPackageModel(
                        $tokens[$classIndex],
                        $imports,
                        $namespaceModules,
                    );
                $writeMethod = $model === null
                    ? null
                    : $this->chainedWriteMethod($tokens, $classIndex);

                if ($model !== null && $writeMethod !== null) {
                    $findings[] = $this->modelFinding(
                        code: 'consumer.package_model_write',
                        package: $model['package'],
                        path: $path,
                        line: $writeMethod->line,
                        symbol: $model['class'].'::'.$writeMethod->text,
                    );
                }

                continue;
            }

            if (! in_array($token->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $separator = $this->nextMeaningfulToken($tokens, $index + 1);

            if ($separator === null || $tokens[$separator]->id !== T_DOUBLE_COLON) {
                continue;
            }

            $methodIndex = $this->nextMeaningfulToken($tokens, $separator + 1);
            $method = $methodIndex === null ? null : $tokens[$methodIndex];

            if ($method === null || $method->id !== T_STRING) {
                continue;
            }

            $openingParenthesis = $this->nextMeaningfulToken($tokens, $methodIndex + 1);

            if ($openingParenthesis === null || $tokens[$openingParenthesis]->text !== '(') {
                continue;
            }

            $model = $this->resolvedPackageModel($token, $imports, $namespaceModules);

            if ($model === null) {
                continue;
            }

            $code = in_array($method->text, self::WRITE_METHODS, true)
                ? 'consumer.package_model_write'
                : 'consumer.package_model_query';

            if ($code !== 'consumer.package_model_query' || ! $adoptionMigration) {
                $findings[] = $this->modelFinding(
                    code: $code,
                    package: $model['package'],
                    path: $path,
                    line: $method->line,
                    symbol: $model['class'].'::'.$method->text,
                );
            }

            $chainedWrite = $this->chainedWriteMethod($tokens, $methodIndex + 1);

            if ($chainedWrite !== null) {
                $findings[] = $this->modelFinding(
                    code: 'consumer.package_model_write',
                    package: $model['package'],
                    path: $path,
                    line: $chainedWrite->line,
                    symbol: $model['class'].'::'.$chainedWrite->text,
                );
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, string>  $namespaceModules
     * @return ModelReference|null
     */
    private function resolvedPackageModel(
        PhpToken $token,
        PhpImportMap $imports,
        array $namespaceModules,
    ): ?array {
        if (! in_array($token->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }

        return $this->packageModelReference(
            $imports->resolve($token->text),
            $namespaceModules,
        );
    }

    /**
     * Resolve the package model and result kind assigned from one static call chain.
     *
     * @param  array<PhpToken>  $tokens
     * @param  array<string, string>  $namespaceModules
     * @return ModelReference|null
     */
    private function assignedStaticPackageModel(
        array $tokens,
        int $classIndex,
        PhpImportMap $imports,
        array $namespaceModules,
    ): ?array {
        $model = $this->resolvedPackageModel(
            $tokens[$classIndex],
            $imports,
            $namespaceModules,
        );
        $separator = $this->nextMeaningfulToken($tokens, $classIndex + 1);

        if ($model === null || $separator === null || $tokens[$separator]->id !== T_DOUBLE_COLON) {
            return null;
        }

        $kind = 'builder';
        $count = count($tokens);

        for ($index = $separator; $index < $count; $index++) {
            if ($tokens[$index]->text === ';') {
                break;
            }

            if (! in_array($tokens[$index]->id, [T_DOUBLE_COLON, T_OBJECT_OPERATOR], true)) {
                continue;
            }

            $methodIndex = $this->nextMeaningfulToken($tokens, $index + 1);
            $method = $methodIndex === null ? null : $tokens[$methodIndex];
            $openingParenthesis = $methodIndex === null
                ? null
                : $this->nextMeaningfulToken($tokens, $methodIndex + 1);

            if ($method === null
                || $method->id !== T_STRING
                || $openingParenthesis === null
                || $tokens[$openingParenthesis]->text !== '(') {
                continue;
            }

            if (in_array($method->text, self::MODEL_RESULT_METHODS, true)) {
                $kind = 'model';
            } elseif (in_array($method->text, self::NON_MODEL_RESULT_METHODS, true)) {
                $kind = 'collection';
            }
        }

        return [...$model, 'kind' => $kind];
    }

    /**
     * Resolve a package model assigned from direct construction.
     *
     * @param  array<PhpToken>  $tokens
     * @param  array<string, string>  $namespaceModules
     * @return ModelReference|null
     */
    private function assignedNewPackageModel(
        array $tokens,
        int $rightHandSide,
        PhpImportMap $imports,
        array $namespaceModules,
    ): ?array {
        if ($tokens[$rightHandSide]->id !== T_NEW) {
            return null;
        }

        $classIndex = $this->nextMeaningfulToken($tokens, $rightHandSide + 1);

        return $classIndex === null
            ? null
            : $this->resolvedPackageModel(
                $tokens[$classIndex],
                $imports,
                $namespaceModules,
            );
    }

    /**
     * Resolve the package model returned by an imported package callable.
     *
     * @param  array<string, string>  $namespaceModules
     * @return ModelReference|null
     */
    private function resolvedPackageModelReturn(
        PhpToken $token,
        PhpImportMap $imports,
        array $namespaceModules,
    ): ?array {
        if (! in_array($token->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }

        $class = $imports->resolve($token->text);

        try {
            if (! class_exists($class) || ! method_exists($class, 'execute')) {
                return null;
            }

            $returnType = (new ReflectionMethod($class, 'execute'))->getReturnType();

            if (! $returnType instanceof ReflectionNamedType || $returnType->isBuiltin()) {
                return null;
            }

            return $this->packageModelReference($returnType->getName(), $namespaceModules);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolve package-model return types for promoted or declared Action properties.
     *
     * @param  array<PhpToken>  $tokens
     * @param  array<string, string>  $namespaceModules
     * @return array<string, ModelReference>
     */
    private function propertyPackageModelReturns(
        array $tokens,
        PhpImportMap $imports,
        array $namespaceModules,
    ): array {
        $properties = [];

        foreach ($tokens as $index => $token) {
            if ($token->id !== T_VARIABLE) {
                continue;
            }

            $typeIndex = $this->previousMeaningfulToken($tokens, $index - 1);

            if ($typeIndex === null || ! $this->propertyTypeDeclaration($tokens, $typeIndex)) {
                continue;
            }

            $model = $this->resolvedPackageModelReturn(
                $tokens[$typeIndex],
                $imports,
                $namespaceModules,
            );

            if ($model !== null) {
                $properties[$token->text] = $model;
            }
        }

        return $properties;
    }

    /**
     * Return whether a type token belongs to a declared or promoted property.
     *
     * @param  array<PhpToken>  $tokens
     */
    private function propertyTypeDeclaration(array $tokens, int $typeIndex): bool
    {
        $index = $this->previousMeaningfulToken($tokens, $typeIndex - 1);

        while ($index !== null) {
            $token = $tokens[$index];

            if (in_array($token->id, [T_PRIVATE, T_PROTECTED, T_PUBLIC], true)) {
                return true;
            }

            if (! in_array($token->id, [T_READONLY, T_STATIC], true)) {
                return false;
            }

            $index = $this->previousMeaningfulToken($tokens, $index - 1);
        }

        return false;
    }

    /**
     * Resolve an assignment from a typed package callable's execute result.
     *
     * @param  array<PhpToken>  $tokens
     * @param  array<string, ModelReference>  $variableReturnModels
     * @param  array<string, ModelReference>  $propertyReturnModels
     * @return ModelReference|null
     */
    private function assignedPackageModelReturn(
        array $tokens,
        int $rightHandSide,
        array $variableReturnModels,
        array $propertyReturnModels,
    ): ?array {
        $variable = $tokens[$rightHandSide];
        $model = $variable->id === T_VARIABLE
            ? ($variableReturnModels[$variable->text] ?? null)
            : null;
        $operator = $this->nextMeaningfulToken($tokens, $rightHandSide + 1);

        if ($variable->id === T_VARIABLE
            && $variable->text === '$this'
            && $operator !== null
            && $tokens[$operator]->id === T_OBJECT_OPERATOR) {
            $propertyIndex = $this->nextMeaningfulToken($tokens, $operator + 1);
            $property = $propertyIndex === null ? null : $tokens[$propertyIndex];

            if ($property !== null && $property->id === T_STRING) {
                $model = $propertyReturnModels['$'.$property->text] ?? null;
                $operator = $this->nextMeaningfulToken($tokens, $propertyIndex + 1);
            }
        }

        $methodIndex = $operator === null
            ? null
            : $this->nextMeaningfulToken($tokens, $operator + 1);
        $method = $methodIndex === null ? null : $tokens[$methodIndex];
        $openingParenthesis = $methodIndex === null
            ? null
            : $this->nextMeaningfulToken($tokens, $methodIndex + 1);

        if ($model === null
            || $operator === null
            || $tokens[$operator]->id !== T_OBJECT_OPERATOR
            || $method === null
            || $method->id !== T_STRING
            || $method->text !== 'execute'
            || $openingParenthesis === null
            || $tokens[$openingParenthesis]->text !== '(') {
            return null;
        }

        return $model;
    }

    /**
     * @param  array<string, string>  $namespaceModules
     * @return ModelReference|null
     */
    private function packageModelReference(string $class, array $namespaceModules): ?array
    {

        if (preg_match('/^Nvl\\\\([^\\\\]+)\\\\Models\\\\/', $class, $matches) !== 1) {
            return null;
        }

        $package = $namespaceModules[strtolower($matches[1])] ?? null;

        return $package === null ? null : [
            'class' => $class,
            'package' => $package,
            'kind' => 'model',
        ];
    }

    /**
     * @param  VariableModels  $variableModels
     * @param  ModelReference|null  $model
     * @return VariableModels
     */
    private function withVariableModel(
        array $variableModels,
        int $scope,
        string $variable,
        ?array $model,
    ): array {
        if ($model === null) {
            unset($variableModels[$scope][$variable]);

            return $variableModels;
        }

        $variableModels[$scope][$variable] = $model;

        return $variableModels;
    }

    private function modelFinding(
        string $code,
        string $package,
        string $path,
        int $line,
        string $symbol,
    ): ConsumerAuditFinding {
        $write = $code === 'consumer.package_model_write';

        return new ConsumerAuditFinding(
            code: $code,
            severity: 'error',
            package: $package,
            path: $path,
            line: $line,
            symbol: $symbol,
            message: $write
                ? 'Application code writes through a package-owned Eloquent model.'
                : 'Application code queries a package-owned Eloquent model directly.',
            remediation: $write
                ? 'Use the package Action, service, or documented mutation contract.'
                : 'Use the package query Action or service; consumer-initiated package model queries are prohibited in 2.0.',
        );
    }

    /**
     * Return the first invoked instance method when it is not an allowed identity operation.
     *
     * @param  array<PhpToken>  $tokens
     */
    private function directInstanceQueryMethod(
        array $tokens,
        int $operator,
        string $modelClass,
    ): ?PhpToken {
        $methodIndex = $this->nextMeaningfulToken($tokens, $operator + 1);
        $method = $methodIndex === null ? null : $tokens[$methodIndex];
        $openingParenthesis = $methodIndex === null
            ? null
            : $this->nextMeaningfulToken($tokens, $methodIndex + 1);

        if ($method === null
            || $method->id !== T_STRING
            || in_array($method->text, self::ALLOWED_IDENTITY_METHODS, true)
            || $this->allowedOwnerTraitMethod($modelClass, $method->text)) {
            return null;
        }

        if ($openingParenthesis === null || $tokens[$openingParenthesis]->text !== '(') {
            return $this->relationMethod($modelClass, $method->text)
                ? $method
                : null;
        }

        if (in_array($method->text, self::INSTANCE_QUERY_METHODS, true)
            || $this->relationMethod($modelClass, $method->text)
            || ! method_exists($modelClass, $method->text)) {
            return $method;
        }

        return null;
    }

    private function allowedOwnerTraitMethod(string $modelClass, string $method): bool
    {
        try {
            $traits = class_uses_recursive($modelClass);

            foreach (self::OWNER_TRAITS as $trait) {
                if (isset($traits[$trait]) && method_exists($trait, $method)) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    private function relationMethod(string $modelClass, string $method): bool
    {
        try {
            if (! method_exists($modelClass, $method)) {
                return false;
            }

            $returnType = (new ReflectionMethod($modelClass, $method))->getReturnType();

            return $returnType instanceof ReflectionNamedType
                && ! $returnType->isBuiltin()
                && is_a($returnType->getName(), Relation::class, true);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<PhpToken>  $tokens
     */
    private function chainedWriteMethod(array $tokens, int $index): ?PhpToken
    {
        $count = count($tokens);

        while ($index < $count) {
            $token = $tokens[$index];

            if ($token->text === ';') {
                return null;
            }

            if ($token->id !== T_OBJECT_OPERATOR) {
                $index++;

                continue;
            }

            $methodIndex = $this->nextMeaningfulToken($tokens, $index + 1);
            $method = $methodIndex === null ? null : $tokens[$methodIndex];
            $openingParenthesis = $methodIndex === null
                ? null
                : $this->nextMeaningfulToken($tokens, $methodIndex + 1);

            if ($method !== null
                && $method->id === T_STRING
                && $openingParenthesis !== null
                && $tokens[$openingParenthesis]->text === '('
                && in_array($method->text, self::WRITE_METHODS, true)) {
                return $method;
            }

            $index++;
        }

        return null;
    }

    /**
     * @param  array<PhpToken>  $tokens
     * @return array{
     *     tokens: array<array-key, int>,
     *     parents: array<int, int>,
     *     arrow_bodies: array<int, int>
     * }
     */
    private function tokenScopes(array $tokens): array
    {
        $scopes = [];
        $parents = [];
        $arrowBodies = [];
        $scope = 0;
        $nextScope = 0;
        $pendingFunction = null;
        $stack = [];

        foreach ($tokens as $index => $token) {
            if ($token->id === T_FUNCTION) {
                $pendingFunction = ++$nextScope;
                $parents[$pendingFunction] = $scope;
            }

            $scopes[$index] = $pendingFunction ?? $scope;

            if ($token->text === '{') {
                $stack[] = $scope;

                if ($pendingFunction !== null) {
                    $scope = $pendingFunction;
                    $pendingFunction = null;
                }
            } elseif ($token->text === '}') {
                $scope = array_pop($stack) ?? 0;
            } elseif ($token->text === ';' && $pendingFunction !== null) {
                $pendingFunction = null;
            }
        }

        foreach ($tokens as $index => $token) {
            if ($token->id !== T_FN) {
                continue;
            }

            $bounds = $this->arrowFunctionBounds($tokens, $index);

            if ($bounds === null) {
                continue;
            }

            $parentScope = $scopes[$index] ?? 0;
            $arrowScope = ++$nextScope;
            $parents[$arrowScope] = $parentScope;
            $arrowBodies[$arrowScope] = $bounds['body'];

            for ($tokenIndex = $index; $tokenIndex <= $bounds['end']; $tokenIndex++) {
                if (($scopes[$tokenIndex] ?? 0) === $parentScope) {
                    $scopes[$tokenIndex] = $arrowScope;
                }
            }
        }

        return [
            'tokens' => $scopes,
            'parents' => $parents,
            'arrow_bodies' => $arrowBodies,
        ];
    }

    /**
     * Resolve the body and expression boundary of an arrow function.
     *
     * @param  array<PhpToken>  $tokens
     * @return array{body: int, end: int}|null
     */
    private function arrowFunctionBounds(array $tokens, int $index): ?array
    {
        $count = count($tokens);
        $doubleArrow = null;

        while ($index < $count) {
            if ($tokens[$index]->id === T_DOUBLE_ARROW) {
                $doubleArrow = $index;

                break;
            }

            if ($tokens[$index]->text === ';') {
                return null;
            }

            $index++;
        }

        $body = $doubleArrow === null
            ? null
            : $this->nextMeaningfulToken($tokens, $doubleArrow + 1);

        if ($body === null) {
            return null;
        }

        $depth = 0;
        $end = $body;

        for ($index = $body; $index < $count; $index++) {
            $text = $tokens[$index]->text;

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    return [
                        'body' => $body,
                        'end' => $this->previousMeaningfulToken($tokens, $index - 1) ?? $body,
                    ];
                }

                $depth--;
            } elseif ($depth === 0 && in_array($text, [',', ';'], true)) {
                return [
                    'body' => $body,
                    'end' => $this->previousMeaningfulToken($tokens, $index - 1) ?? $body,
                ];
            }

            $end = $index;
        }

        return ['body' => $body, 'end' => $end];
    }

    /**
     * Return whether a variable is declared in a closure use list.
     *
     * @param  array<PhpToken>  $tokens
     */
    private function closureCaptureVariable(array $tokens, int $index): bool
    {
        $index = $this->previousMeaningfulToken($tokens, $index - 1);

        while ($index !== null) {
            $token = $tokens[$index];

            if ($token->id === T_USE) {
                return true;
            }

            if (in_array($token->id, [T_FUNCTION, T_FN], true)
                || in_array($token->text, ['{', '}', ';'], true)) {
                return false;
            }

            $index = $this->previousMeaningfulToken($tokens, $index - 1);
        }

        return false;
    }

    /**
     * @param  array<string, string>  $tableOwners
     * @param  list<string>  $enabledPackages
     * @return list<ConsumerAuditFinding>
     */
    private function tableFindings(
        string $source,
        string $path,
        array $tableOwners,
        array $enabledPackages,
    ): array {
        $migration = preg_match('~(?:^|/)database/migrations/~', $path) === 1;
        $enabled = array_fill_keys($enabledPackages, true);
        $findings = [];
        $reported = [];
        $tokens = PhpToken::tokenize($source, TOKEN_PARSE);
        $scopeMap = $this->tokenScopes($tokens);
        $indirectTableWrites = $migration
            ? $this->indirectTableWriteMethods(
                $tokens,
                $tableOwners,
                $scopeMap['tokens'],
                $scopeMap['parents'],
                $scopeMap['arrow_bodies'],
            )
            : [];

        foreach ($tokens as $index => $token) {
            if ($token->id !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $table = $this->literalValue($token->text);
            $package = $tableOwners[$table] ?? null;

            if ($package === null) {
                continue;
            }

            $duplicate = $migration
                && isset($enabled[$package])
                && preg_match(
                    '/(?:Schema|\\\\Illuminate\\\\Support\\\\Facades\\\\Schema)\\s*::\\s*create\\s*\\(\\s*[\'\"]'.preg_quote($table, '/').'[\'\"]/i',
                    $source,
                ) === 1;
            $writeMethod = $migration
                ? ($indirectTableWrites[$index] ?? $this->rawTableWriteMethod($tokens, $index))
                : null;
            $code = match (true) {
                $duplicate => 'consumer.duplicate_package_migration',
                $writeMethod !== null => 'consumer.package_table_reference',
                $migration => 'consumer.package_migration_reference',
                default => 'consumer.package_table_reference',
            };
            $severity = $migration && ! $duplicate && $writeMethod === null
                ? 'warning'
                : 'error';
            $reportedKey = $code.'|'.$table.'|'.($writeMethod->text ?? 'reference');

            if (isset($reported[$reportedKey])
                || (! $migration && ! $this->isRawTableReference($source, $table))) {
                continue;
            }

            $reported[$reportedKey] = true;

            $findings[] = new ConsumerAuditFinding(
                code: $code,
                severity: $severity,
                package: $package,
                path: $path,
                line: $token->line,
                symbol: $writeMethod === null ? $table : $table.'::'.$writeMethod->text,
                message: match ($code) {
                    'consumer.duplicate_package_migration' => 'A consumer migration creates a table owned by an enabled package.',
                    'consumer.package_migration_reference' => 'A consumer adoption migration references a package-owned table.',
                    default => $writeMethod === null
                        ? 'Application code references a package-owned table name directly.'
                        : 'A consumer adoption migration writes directly to a package-owned table.',
                },
                remediation: match (true) {
                    $duplicate => 'Remove the duplicate migration and use the package migration ownership switch.',
                    $writeMethod !== null => 'Move the mutation behind a documented package Action or adoption API.',
                    default => 'Use the package table definition or a documented package API.',
                },
            );
        }

        return $findings;
    }

    /**
     * Resolve mutations made through same-scope literal package-table variables.
     *
     * @param  array<PhpToken>  $tokens
     * @param  array<string, string>  $tableOwners
     * @param  array<array-key, int>  $scopes
     * @param  array<int, int>  $scopeParents
     * @param  array<int, int>  $arrowBodies
     * @return array<int, PhpToken>
     */
    private function indirectTableWriteMethods(
        array $tokens,
        array $tableOwners,
        array $scopes,
        array $scopeParents,
        array $arrowBodies,
    ): array {
        /** @var array<int, array<string, int|null>> $variables */
        $variables = [];
        $writes = [];

        foreach ($tokens as $index => $token) {
            $scope = $scopes[$index] ?? 0;

            if ($token->id === T_FN) {
                $parentScope = $scopeParents[$scope] ?? null;

                if ($parentScope !== null) {
                    $variables[$scope] = $variables[$parentScope] ?? [];
                }

                continue;
            }

            if ($token->id !== T_VARIABLE) {
                continue;
            }

            if (isset($arrowBodies[$scope]) && $index < $arrowBodies[$scope]) {
                $variables[$scope][$token->text] = null;

                continue;
            }

            $nextIndex = $this->nextMeaningfulToken($tokens, $index + 1);
            $next = $nextIndex === null ? null : $tokens[$nextIndex];

            if ($next !== null && $this->assignmentOperator($next)) {
                $variables[$scope][$token->text] = null;

                if ($next->text !== '=') {
                    continue;
                }

                $literalIndex = $this->nextMeaningfulToken($tokens, $nextIndex + 1);
                $literal = $literalIndex === null ? null : $tokens[$literalIndex];

                if ($literal !== null
                    && $literal->id === T_CONSTANT_ENCAPSED_STRING
                    && isset($tableOwners[$this->literalValue($literal->text)])) {
                    $variables[$scope][$token->text] = $literalIndex;
                }

                continue;
            }

            $literalIndex = $variables[$scope][$token->text] ?? null;

            if ($literalIndex === null || ! $this->staticTableCallArgument($tokens, $index)) {
                continue;
            }

            $writeMethod = $this->rawTableWriteMethod($tokens, $index);

            if ($writeMethod !== null) {
                $writes[$literalIndex] ??= $writeMethod;
            }
        }

        return $writes;
    }

    /**
     * Return whether a token mutates a tracked variable assignment.
     */
    private function assignmentOperator(PhpToken $token): bool
    {
        return in_array($token->text, [
            '=',
            '+=',
            '-=',
            '*=',
            '/=',
            '.=',
            '%=',
            '&=',
            '|=',
            '^=',
            '<<=',
            '>>=',
            '??=',
            '**=',
        ], true);
    }

    /**
     * Return the first raw query-builder mutation chained from a package table literal.
     *
     * @param  array<PhpToken>  $tokens
     */
    private function rawTableWriteMethod(array $tokens, int $index): ?PhpToken
    {
        if (! $this->staticTableCallArgument($tokens, $index)) {
            return null;
        }

        $openingParenthesis = $this->previousMeaningfulToken($tokens, $index - 1);
        $closingParenthesis = $openingParenthesis === null
            ? null
            : $this->matchingClosingParenthesis($tokens, $openingParenthesis);
        $operatorIndex = $closingParenthesis === null
            ? null
            : $this->nextMeaningfulToken($tokens, $closingParenthesis + 1);

        while ($operatorIndex !== null
            && in_array($tokens[$operatorIndex]->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            $methodIndex = $this->nextMeaningfulToken($tokens, $operatorIndex + 1);
            $method = $methodIndex === null ? null : $tokens[$methodIndex];
            $methodOpeningParenthesis = $methodIndex === null
                ? null
                : $this->nextMeaningfulToken($tokens, $methodIndex + 1);

            if ($method === null
                || $method->id !== T_STRING
                || $methodOpeningParenthesis === null
                || $tokens[$methodOpeningParenthesis]->text !== '(') {
                return null;
            }

            if (in_array($method->text, self::RAW_TABLE_WRITE_METHODS, true)) {
                return $method;
            }

            $methodClosingParenthesis = $this->matchingClosingParenthesis(
                $tokens,
                $methodOpeningParenthesis,
            );
            $operatorIndex = $methodClosingParenthesis === null
                ? null
                : $this->nextMeaningfulToken($tokens, $methodClosingParenthesis + 1);
        }

        return null;
    }

    /**
     * Return whether a token is the first argument of a DB or Schema table call.
     *
     * @param  array<PhpToken>  $tokens
     */
    private function staticTableCallArgument(array $tokens, int $index): bool
    {
        $openingParenthesis = $this->previousMeaningfulToken($tokens, $index - 1);
        $methodIndex = $openingParenthesis === null
            ? null
            : $this->previousMeaningfulToken($tokens, $openingParenthesis - 1);
        $operatorIndex = $methodIndex === null
            ? null
            : $this->previousMeaningfulToken($tokens, $methodIndex - 1);
        $classIndex = $operatorIndex === null
            ? null
            : $this->previousMeaningfulToken($tokens, $operatorIndex - 1);

        if ($openingParenthesis === null
            || $tokens[$openingParenthesis]->text !== '('
            || $methodIndex === null
            || $tokens[$methodIndex]->id !== T_STRING
            || $tokens[$methodIndex]->text !== 'table'
            || $operatorIndex === null
            || $tokens[$operatorIndex]->id !== T_DOUBLE_COLON
            || $classIndex === null) {
            return false;
        }

        return preg_match('/(?:^|\\\\)(?:DB|Schema)$/', $tokens[$classIndex]->text) === 1;
    }

    /**
     * Return the closing parenthesis paired with an opening parenthesis.
     *
     * @param  array<PhpToken>  $tokens
     */
    private function matchingClosingParenthesis(array $tokens, int $openingParenthesis): ?int
    {
        $depth = 0;

        foreach ($tokens as $index => $token) {
            if ($index < $openingParenthesis) {
                continue;
            }

            if ($token->text === '(') {
                $depth++;
            } elseif ($token->text === ')') {
                $depth--;

                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
    }

    private function isRawTableReference(string $source, string $table): bool
    {
        $quotedTable = '[\'\"]'.preg_quote($table, '/').'[\'\"]';

        return preg_match(
            '/(?:(?:Schema|DB|[A-Za-z_][A-Za-z0-9_\\\\]*)\\s*::|->)\\s*'
            .'(?:create|table|from|join|leftJoin|rightJoin|crossJoin)\\s*\\(\\s*'.$quotedTable
            .'|\\$table\\s*=\\s*'.$quotedTable.'/i',
            $source,
        ) === 1;
    }

    private function literalValue(string $literal): string
    {
        $quote = $literal[0] ?? '';
        $value = substr($literal, 1, -1);

        return $quote === '"'
            ? stripcslashes($value)
            : str_replace(['\\\\', "\\'"], ['\\', "'"], $value);
    }

    /**
     * @param  array<PhpToken>  $tokens
     */
    private function nextMeaningfulToken(array $tokens, int $index): ?int
    {
        $count = count($tokens);

        while ($index < $count) {
            if (! in_array($tokens[$index]->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $index;
            }

            $index++;
        }

        return null;
    }

    /**
     * @param  array<PhpToken>  $tokens
     */
    private function previousMeaningfulToken(array $tokens, int $index): ?int
    {
        while ($index >= 0) {
            if (! in_array($tokens[$index]->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $index;
            }

            $index--;
        }

        return null;
    }

    /**
     * @param  list<ConsumerAuditFinding>  $findings
     * @return list<ConsumerAuditFinding>
     */
    private function sortedUnique(array $findings): array
    {
        $unique = [];

        foreach ($findings as $finding) {
            $key = implode('|', [
                $finding->code,
                $finding->path,
                (string) $finding->line,
                $finding->symbol,
            ]);
            $unique[$key] = $finding;
        }

        ksort($unique);

        return array_values($unique);
    }
}
