<?php

declare(strict_types=1);

namespace Nvl\Pages\Contracts;

use Illuminate\Database\Eloquent\Model;

/** Declares the registered tenant resource queried by a dynamic Page handler. */
interface TenantSafePageResourceHandler extends PageResourceHandler
{
    /** @return class-string<Model> */
    public function tenantResourceModel(): string;
}
