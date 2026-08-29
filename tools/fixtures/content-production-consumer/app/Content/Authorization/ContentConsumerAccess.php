<?php

declare(strict_types=1);

namespace App\Content\Authorization;

use Illuminate\Auth\Access\AuthorizationException;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Media\Data\MediaActorData;
use Nvl\Pages\Data\PageActorData;
use Nvl\Pages\Enums\PageAbility;

/** Shared explicit policy state used by the package-specific typed adapters. */
final class ContentConsumerAccess
{
    private bool $denyAll = false;

    public function authorizeContent(ContentAbility $ability, ContentActorData $actor): void
    {
        if (! $this->denyAll
            && $actor->system
            && $actor->toArray() === ContentActorData::system()->toArray()) {
            return;
        }

        if (! $this->denyAll
            && $actor->type === null
            && $actor->id === null
            && in_array($ability, [ContentAbility::Render, ContentAbility::View], true)) {
            return;
        }

        throw new AuthorizationException("Content ability [{$ability->value}] denied.");
    }

    public function allowsMedia(MediaActorData $actor): bool
    {
        return ! $this->denyAll
            && $actor->system
            && $actor->toArray() === MediaActorData::system()->toArray();
    }

    public function authorizePage(PageAbility $ability, PageActorData $actor): void
    {
        if (! $this->denyAll
            && $actor->system
            && $actor->toArray() === PageActorData::system()->toArray()) {
            return;
        }

        if (! $this->denyAll
            && $actor->type === null
            && $actor->id === null
            && in_array($ability, [PageAbility::View, PageAbility::ViewNavigation], true)) {
            return;
        }

        throw new AuthorizationException("Page ability [{$ability->value}] denied.");
    }

    public function authorizeManagement(string $boundary): void
    {
        if ($this->denyAll) {
            throw new AuthorizationException(ucfirst($boundary).' access denied.');
        }
    }

    /** Execute a proof assertion with every package boundary denied. */
    public function denying(callable $callback): mixed
    {
        $this->denyAll = true;

        try {
            return $callback();
        } finally {
            $this->denyAll = false;
        }
    }
}
