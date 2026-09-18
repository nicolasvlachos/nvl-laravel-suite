<?php

declare(strict_types=1);

namespace Nvl\Pages\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Declares the registered tenant resource queried by a dynamic Page handler.
 *
 * @template TResource of Model
 *
 * @extends PageResourceHandler<TResource>
 */
interface TenantSafePageResourceHandler extends PageResourceHandler
{
    /** @return class-string<TResource> */
    public function tenantResourceModel(): string;
}
