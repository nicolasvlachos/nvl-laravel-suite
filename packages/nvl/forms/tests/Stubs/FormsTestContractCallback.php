<?php

declare(strict_types=1);

namespace Nvl\Forms\Tests\Stubs;

use Illuminate\Http\Request;
use Nvl\Forms\Contracts\EntrySubmissionCallback;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;

/**
 * Records contract-based submission callback invocations for Forms tests.
 */
final class FormsTestContractCallback implements EntrySubmissionCallback
{
    public static int $calls = 0;

    /**
     * Record one completed form-entry callback invocation.
     */
    public function after(Form $form, FormEntry $entry, Request $request): void
    {
        self::$calls++;
    }
}
