<?php

declare(strict_types=1);

namespace Nvl\Content\Data;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Stable actor identity for authorization, revisions, and events.
 */
#[TypeScript]
final class ContentActorData extends Data
{
    public function __construct(
        public readonly ?string $type,
        public readonly int|string|null $id,
        public readonly bool $system = false,
    ) {}

    public static function fromAuthenticatable(Authenticatable $actor): self
    {
        $identifier = $actor->getAuthIdentifier();

        return new self(
            type: $actor instanceof Model ? $actor->getMorphClass() : $actor::class,
            id: is_int($identifier) || is_string($identifier) ? $identifier : null,
        );
    }

    public static function system(): self
    {
        return new self('system', null, system: true);
    }
}
