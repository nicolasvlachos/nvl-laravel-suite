<?php

declare(strict_types=1);

use Nvl\Auth\Models\Role;

Role::query()->whereNull('display_name')->update(['display_name' => 'Role']);
