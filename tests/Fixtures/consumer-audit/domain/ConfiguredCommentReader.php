<?php

declare(strict_types=1);

namespace ConsumerDomain;

use Nvl\Comments\Models\Comment;

return new class
{
    public function first(): ?Comment
    {
        return Comment::first();
    }
};
