<?php

declare(strict_types=1);

return [
    'general' => [
        'yes' => 'Да',
        'no' => 'Не',
    ],
    'tables' => [
        'boolean' => [
            'yes' => 'Да',
            'no' => 'Не',
        ],
        'ui' => [
            'empty' => 'Няма стойност',
        ],
    ],
    'messages' => [
        'error' => [
            'authentication_required' => 'За това действие е необходима автентикация.',
            'permission_denied' => 'Нямате права за това действие.',
            'refresh_failed' => 'Неуспешно опресняване на :item след операцията.',
            'rate_limit_exceeded' => 'Достигнат е лимитът на заявки. Опитайте по-късно.',
            'bot_detected' => 'Засечено е автоматизирано изпращане. Заявката е блокирана.',
            'origin_not_allowed' => 'Заявките не са позволени от този произход: :origin.',
            'ownership_mismatch' => ':item не принадлежи към посочения :parent.',
            'no_export_data' => 'Няма намерени :items за експортиране.',
            'not_found' => ':item не е намерен.',
            'delete_failed' => 'Неуспешно изтриване на :item.',
        ],
    ],
];
