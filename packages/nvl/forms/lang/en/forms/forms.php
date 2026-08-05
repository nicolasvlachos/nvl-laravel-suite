<?php

declare(strict_types=1);

return [
    'fields' => [
        'status' => [
            'label' => 'Status',
            'help' => 'Controls whether this form may accept submissions.',
        ],
        'restrict_public_access' => [
            'label' => 'Restrict public access',
            'help' => 'Require the form to be accessed through an allowed integration.',
        ],
    ],
    'additional_fields' => [
        'submissions_count' => [
            'label' => 'Submissions',
            'help' => 'Total recorded submissions.',
        ],
        'views_count' => [
            'label' => 'Views',
            'help' => 'Total recorded form views.',
        ],
        'spam_count' => [
            'label' => 'Spam',
            'help' => 'Submissions classified as spam.',
        ],
        'last_used_at' => [
            'label' => 'Last used',
            'help' => 'The most recent recorded interaction.',
        ],
    ],
    'options' => [
        'status' => [
            'draft' => 'Draft',
            'active' => 'Active',
            'paused' => 'Paused',
            'archived' => 'Archived',
        ],
        'cors_policy' => [
            'strict' => 'Strict',
            'moderate' => 'Moderate',
            'permissive' => 'Permissive',
            'custom' => 'Custom',
        ],
        'event_types' => [
            'view' => 'Form View',
            'submission' => 'Form Submission',
            'spam_blocked' => 'Spam Blocked',
            'rate_limited' => 'Rate Limited',
            'error' => 'Error',
            'validation_failed' => 'Validation Failed',
        ],
        'resolvement' => [
            'entries' => 'Entries',
            'custom' => 'Custom',
        ],
        'type' => [
            'landing_page' => 'Landing Page',
            'iframe' => 'Iframe',
        ],
        'access' => [
            'public' => 'Public',
            'embedded' => 'Embedded',
            'private' => 'Private',
        ],
    ],
    'descriptions' => [
        'status' => [
            'draft' => 'The form is being prepared and is not published.',
            'active' => 'The form is live and accepting submissions.',
            'paused' => 'The form is temporarily not accepting submissions.',
            'archived' => 'The form is retained for historical access.',
        ],
        'event_types' => [
            'view' => 'A visitor viewed the form.',
            'submission' => 'A visitor submitted the form successfully.',
            'spam_blocked' => 'A submission was blocked as spam.',
            'rate_limited' => 'A submission was blocked by rate limiting.',
            'error' => 'An error occurred while processing the form.',
            'validation_failed' => 'A submitted payload failed validation.',
        ],
        'cors_policy' => [
            'strict' => 'Allow only exact origin matches.',
            'moderate' => 'Allow configured origins and supported subdomain patterns.',
            'permissive' => 'Allow broad wildcard origin patterns.',
            'custom' => 'Use a consumer-defined CORS policy.',
        ],
    ],
];
