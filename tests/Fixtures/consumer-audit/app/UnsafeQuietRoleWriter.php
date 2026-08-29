<?php

declare(strict_types=1);

namespace Consumer;

use Nvl\Auth\Models\Role;
use Nvl\Pages\Models\Page;

return new class
{
    public function writeQuietly(): void
    {
        Role::query()->createQuietly(['name' => 'manager']);

        $role = new Role;
        $role->saveQuietly();
        $role->updateQuietly(['display_name' => 'Manager']);
        $role->pushQuietly();
        $role->touchQuietly();
        $role->deleteQuietly();

        $page = new Page;
        $page->forceDeleteQuietly();
        $page->restoreQuietly();
    }
};
