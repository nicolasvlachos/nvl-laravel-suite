<?php

declare(strict_types=1);

namespace Modules\Inventory;

use Nvl\Pages\Models\Page;

return new class
{
    public function find(string $id): ?Page
    {
        return Page::find($id);
    }
};
