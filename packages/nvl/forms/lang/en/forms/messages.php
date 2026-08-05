<?php

declare(strict_types=1);

return [
    'error' => [
        'submission_failed' => 'Failed to submit form. Check the submitted data and try again.',
        'csrf_failed' => 'Security token validation failed. Reload the form and try again.',
        'host_not_allowed' => 'Submission is not allowed from this host.',
        'origin_required' => 'A submission origin is required.',
        'request_denied' => 'The request was denied.',
        'iframe_only' => 'This form may only be accessed in an iframe.',
        'handle_exists' => 'A form with handle ":handle" already exists.',
        'cannot_delete_with_entries' => 'A form with existing submissions cannot be deleted.',
        'compliance_restriction' => 'The requested deletion is restricted by the retention policy.',
        'registration_identity_required' => 'An email address or active session is required for this registration.',
        'registration_already_exists' => 'A registration already exists for this form.',
        'idempotency_conflict' => 'The idempotency key was already used with a different payload.',
        'submission_conflict' => 'This submission is already being processed or could not be safely retried.',
    ],
    'warning' => [
        'submission_recording_delayed' => 'Your submission succeeded, but internal form bookkeeping could not be fully recorded immediately.',
    ],
    'api' => [
        'form_load_error' => 'The form could not be loaded.',
        'form_load_error_detail' => 'An error occurred while loading the form. Try again.',
        'form_submitted' => 'The form was submitted successfully.',
        'validation_failed' => 'Form validation failed.',
        'validation_failed_detail' => 'Correct the highlighted errors and try again.',
        'submission_failed' => 'Form submission failed.',
        'form_not_found' => 'Form not found.',
        'form_unavailable' => 'This form is not currently available.',
        'schema_load_error' => 'The form schema could not be loaded.',
        'error' => 'An error occurred.',
    ],
];
