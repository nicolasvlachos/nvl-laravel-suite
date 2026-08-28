<?php

declare(strict_types=1);

use Nvl\Templates\Models\Template;

return new class
{
    public function first(): ?Template
    {
        return Template::query()->first();
    }
};
