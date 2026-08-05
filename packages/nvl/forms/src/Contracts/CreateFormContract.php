<?php

declare(strict_types=1);

namespace Nvl\Forms\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Forms\Data\Mutations\MutateFormPayload;
use Nvl\Forms\Models\Form;

interface CreateFormContract
{
    /**
     * Execute the form creation within a database transaction.
     *
     * @param  MutateFormPayload  $data  Validated form mutation data
     * @param  Authenticatable|null  $actor  Authenticated actor performing the operation
     * @return Form The created form instance
     */
    public function execute(MutateFormPayload $data, ?Authenticatable $actor = null): Form;
}
