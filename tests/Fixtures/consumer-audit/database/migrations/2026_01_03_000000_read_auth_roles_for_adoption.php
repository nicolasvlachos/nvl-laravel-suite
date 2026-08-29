<?php

declare(strict_types=1);

use Nvl\Auth\Models\Role;

Role::query()->whereNotNull('name')->get();
