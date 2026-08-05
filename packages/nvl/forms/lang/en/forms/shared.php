<?php

declare(strict_types=1);

return [
    'general' => [
        'yes' => 'Yes',
        'no' => 'No',
    ],
    'tables' => [
        'boolean' => [
            'yes' => 'Yes',
            'no' => 'No',
        ],
        'ui' => [
            'empty' => 'No value',
        ],
    ],
    'messages' => [
        'error' => [
            'authentication_required' => 'Authentication is required to perform this action.',
            'permission_denied' => 'You do not have permission to perform this action.',
            'refresh_failed' => 'Failed to refresh :item after the operation.',
            'rate_limit_exceeded' => 'Rate limit exceeded. Try again later.',
            'bot_detected' => 'Automated submission detected. The request was blocked.',
            'origin_not_allowed' => 'Requests are not allowed from this origin: :origin.',
            'ownership_mismatch' => ':item does not belong to the specified :parent.',
            'no_export_data' => 'No :items were found to export.',
            'not_found' => ':item was not found.',
            'delete_failed' => 'Failed to delete :item.',
        ],
    ],
];
