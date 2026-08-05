<?php

declare(strict_types=1);

namespace Nvl\Forms\Results;

use Carbon\CarbonInterface;
use Nvl\Forms\Models\Form;

/**
 * Value object describing a successful public form submission.
 */
final readonly class FormSubmissionResult
{
    /**
     * @param  Form  $form  Submitted form
     * @param  string  $entryId  Persisted or handler-provided entry identifier
     * @param  CarbonInterface  $submittedAt  Submission timestamp
     * @param  bool  $hasBookkeepingWarning  Whether Forms-owned bookkeeping degraded after downstream success
     */
    public function __construct(
        public Form $form,
        public string $entryId,
        public CarbonInterface $submittedAt,
        public bool $hasBookkeepingWarning = false,
    ) {}
}
