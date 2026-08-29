<?php

declare(strict_types=1);

namespace Consumer;

use Illuminate\Database\Eloquent\Model;
use Nvl\Filterable\Traits\Filterable;
use Nvl\Translatable\Translatable;

abstract class AllowedConsumerTraits extends Model
{
    use Filterable;
    use Translatable;
}

AllowedConsumerTraits::query();
AllowedConsumerTraits::withResolvedTranslations('en');
