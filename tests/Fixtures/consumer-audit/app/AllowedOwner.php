<?php

declare(strict_types=1);

namespace Consumer;

use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Concerns\InteractsWithComments;
use Nvl\Comments\Contracts\HasComments;

return new class extends Model implements HasComments
{
    use InteractsWithComments;
};
