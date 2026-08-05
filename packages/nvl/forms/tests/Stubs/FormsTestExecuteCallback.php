<?php

declare(strict_types=1);

namespace Nvl\Forms\Tests\Stubs;

use Illuminate\Http\Request;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;

/**
 * Records executable submission callback invocations for Forms tests.
 */
final class FormsTestExecuteCallback
{
    public static int $calls = 0;

    /**
     * Record one executable form-entry callback invocation.
     */
    public function execute(Form $form, FormEntry $entry, Request $request): void
    {
        self::$calls++;
    }
}
