<?php

declare(strict_types=1);

namespace Consumer;

use Nvl\Auth\Models\Role;

return new class
{
    private const string API_TOKEN = 'sk_live_consumer_secret';

    public function first(): ?Role
    {
        return Role::whereName('manager')->first();
    }
};
