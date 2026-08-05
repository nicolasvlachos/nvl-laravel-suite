<?php

declare(strict_types=1);

namespace Nvl\Translations\Support;

use Nvl\Translations\Enums\TranslationScopeType;

/**
 * Resolved translation disk scope.
 */
final readonly class TranslationScope
{
    /**
     * @param  TranslationScopeType  $type  Scope type
     * @param  string  $name  Scope identifier
     * @param  string  $path  Absolute disk path
     */
    public function __construct(
        public TranslationScopeType $type,
        public string $name,
        public string $path,
    ) {}

    /**
     * Build a scope token usable by CLI options.
     */
    public function token(): string
    {
        if ($this->type === TranslationScopeType::App) {
            return TranslationScopeType::App->value;
        }

        return sprintf('%s:%s', $this->type->value, $this->name);
    }
}
