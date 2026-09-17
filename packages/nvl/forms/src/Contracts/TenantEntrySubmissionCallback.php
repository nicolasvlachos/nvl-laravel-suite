<?php

declare(strict_types=1);

namespace Nvl\Forms\Contracts;

use Nvl\Forms\Data\FormSubmissionCallbackContext;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;

/** Handles a committed tenant submission using scalar request context only. */
interface TenantEntrySubmissionCallback
{
    /** Process one canonically reloaded form entry after commit. */
    public function after(Form $form, FormEntry $entry, FormSubmissionCallbackContext $context): void;
}
