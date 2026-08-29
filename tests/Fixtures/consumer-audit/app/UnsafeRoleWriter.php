<?php

declare(strict_types=1);

namespace Consumer;

use Nvl\Auth\Models\Role;
use Nvl\Pages\Models\Page;

return new class
{
    public function write(): void
    {
        Role::updateOrCreate(['name' => 'manager'], ['guard_name' => 'web']);
        Page::where('slug', 'home')->first();
        $role = Role::query()->firstOrFail();
        $role->forceFill(['display_name' => 'Manager'])->save();
        Role::query()->where('name', 'manager')->update(['display_name' => 'Manager']);
    }
};
