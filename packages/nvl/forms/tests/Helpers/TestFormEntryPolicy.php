<?php

declare(strict_types=1);

namespace Nvl\Forms\Tests\Helpers;

use Nvl\Forms\Models\FormEntry;
use Nvl\Forms\Tests\Stubs\TestFormsUser;

final class TestFormEntryPolicy
{
    public function delete(TestFormsUser $user, FormEntry $entry): bool
    {
        return true;
    }
}
