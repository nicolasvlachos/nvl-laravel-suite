<?php

declare(strict_types=1);

namespace Nvl\Pages\Data;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Content\Data\ContentActorData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Transport-neutral actor identity for authorization and event context.
 */
#[Hidden]
final class PageActorData extends Data
{
    /**
     * Create an actor identity for server-side authorization.
     */
    public function __construct(
        public readonly ?string $type,
        public readonly int|string|null $id,
        public readonly bool $system = false,
    ) {}

    /**
     * Return an anonymous public actor.
     */
    public static function anonymous(): self
    {
        return new self(null, null);
    }

    /**
     * Return a trusted system actor.
     */
    public static function system(): self
    {
        return new self('system', null, true);
    }

    /**
     * Build an actor identity from a Laravel authenticatable.
     */
    public static function fromAuthenticatable(Authenticatable $actor): self
    {
        $identifier = $actor->getAuthIdentifier();

        return new self(
            type: $actor instanceof Model ? $actor->getMorphClass() : $actor::class,
            id: is_int($identifier) || is_string($identifier) ? $identifier : null,
        );
    }

    /**
     * Adapt this identity to the Content authorization boundary.
     */
    public function contentActor(): ContentActorData
    {
        return new ContentActorData($this->type, $this->id, $this->system);
    }
}
