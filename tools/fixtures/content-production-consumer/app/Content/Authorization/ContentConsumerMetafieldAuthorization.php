<?php

declare(strict_types=1);

namespace App\Content\Authorization;

use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Contracts\MetafieldAuthorization;
use Nvl\Metafields\Contracts\MetafieldReferenceAuthorization;
use Nvl\Metafields\Enums\MetafieldAbility;
use Nvl\Metafields\Models\MetafieldDefinition;

/** Typed Metafields and reference authorization adapter. */
final readonly class ContentConsumerMetafieldAuthorization implements MetafieldAuthorization, MetafieldReferenceAuthorization
{
    public function __construct(private ContentConsumerAccess $access) {}

    public function authorizeDefinition(
        MetafieldAbility $ability,
        ?MetafieldDefinition $definition = null,
    ): void {
        $this->access->authorizeManagement('metafield definition');
    }

    public function authorizeOwner(
        MetafieldAbility $ability,
        ?Model $owner = null,
        ?MetafieldDefinition $definition = null,
    ): void {
        $this->access->authorizeManagement('owner metafield');
    }

    public function authorize(
        Model $owner,
        MetafieldDefinition $definition,
        Model $reference,
    ): void {
        $this->access->authorizeManagement('metafield reference');
    }
}
