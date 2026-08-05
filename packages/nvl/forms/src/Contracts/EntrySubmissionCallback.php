<?php

declare(strict_types=1);

namespace Nvl\Forms\Contracts;

use Illuminate\Http\Request;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;

interface EntrySubmissionCallback
{
    /**
     * Execute a callback after a form entry has been committed to the database.
     *
     * Callbacks run outside the entry-creation transaction. The entry is already
     * persisted when this method is invoked. Implementations must be failure-safe:
     * a thrown exception will not roll back the entry and may interrupt subsequent
     * callbacks in the dispatch chain.
     *
     * @param  Form  $form  Form model instance
     * @param  FormEntry  $entry  Persisted entry model instance
     * @param  Request  $request  HTTP request instance
     */
    public function after(Form $form, FormEntry $entry, Request $request): void;
}
