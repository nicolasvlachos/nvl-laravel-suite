<?php

declare(strict_types=1);

namespace Nvl\Suite\Services\ConsumerAudit;

use PhpToken;

/**
 * Resolves PHP class references through a source file's namespace and imports.
 */
final readonly class PhpImportMap
{
    /**
     * @param  array<string, non-empty-string>  $aliases
     */
    private function __construct(
        private string $namespace,
        private array $aliases,
    ) {}

    /**
     * Build an import map from parsed PHP tokens.
     */
    public static function fromSource(string $source): self
    {
        $tokens = PhpToken::tokenize($source, TOKEN_PARSE);
        $namespace = '';
        $aliases = [];
        $braceDepth = 0;

        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if ($token->text === '{') {
                $braceDepth++;

                continue;
            }

            if ($token->text === '}') {
                $braceDepth--;

                continue;
            }

            if ($braceDepth !== 0) {
                continue;
            }

            if ($token->id === T_NAMESPACE) {
                [$namespace, $index] = self::statement($tokens, $index + 1, [';', '{']);
                $namespace = trim($namespace, ' \\t\\n\\r\\0\\x0B\\\\');

                continue;
            }

            if ($token->id !== T_USE) {
                continue;
            }

            [$statement, $index] = self::statement($tokens, $index + 1, [';']);

            foreach (self::parseImports($statement) as $alias => $class) {
                $aliases[strtolower($alias)] = $class;
            }
        }

        return new self($namespace, $aliases);
    }

    /**
     * Resolve one class-like reference to its fully qualified name.
     */
    public function resolve(string $reference): string
    {
        $reference = trim($reference);

        if ($reference === '') {
            return '';
        }

        if (str_starts_with($reference, '\\')) {
            return ltrim($reference, '\\');
        }

        $segments = explode('\\', $reference, 2);
        $first = $segments[0];
        $remainder = $segments[1] ?? null;
        $import = $this->aliases[strtolower($first)] ?? null;

        if ($import !== null) {
            return $remainder === null ? $import : $import.'\\'.$remainder;
        }

        return $this->namespace === '' ? $reference : $this->namespace.'\\'.$reference;
    }

    /**
     * @param  array<PhpToken>  $tokens
     * @param  list<string>  $terminators
     * @return array{string, int}
     */
    private static function statement(array $tokens, int $index, array $terminators): array
    {
        $statement = '';
        $count = count($tokens);

        while ($index < $count && ! in_array($tokens[$index]->text, $terminators, true)) {
            $statement .= $tokens[$index]->text;
            $index++;
        }

        return [$statement, $index];
    }

    /**
     * @return array<string, non-empty-string>
     */
    private static function parseImports(string $statement): array
    {
        $statement = trim($statement);

        if ($statement === '' || preg_match('/^(?:function|const)\\s+/i', $statement) === 1) {
            return [];
        }

        $imports = [];

        if (str_contains($statement, '{')) {
            [$prefix, $members] = explode('{', $statement, 2);
            $prefix = rtrim(trim($prefix), '\\');
            $members = rtrim(trim($members), '}');

            foreach (explode(',', $members) as $member) {
                self::addImport($imports, $prefix.'\\'.trim($member));
            }

            return $imports;
        }

        foreach (explode(',', $statement) as $import) {
            self::addImport($imports, trim($import));
        }

        return $imports;
    }

    /**
     * @param  array<string, non-empty-string>  $imports
     */
    private static function addImport(array &$imports, string $import): void
    {
        if ($import === '') {
            return;
        }

        $parts = preg_split('/\\s+as\\s+/i', $import);
        $class = ltrim(trim((string) ($parts[0] ?? '')), '\\');

        if ($class === '') {
            return;
        }

        $alias = trim((string) ($parts[1] ?? class_basename($class)));

        if ($alias !== '') {
            $imports[$alias] = $class;
        }
    }
}
