<?php

declare(strict_types=1);

return [
    'templates' => [
        'duplicated' => ':actor дублира този :subject.',
        'submission_received' => ':actor регистрира ново :value изпращане за този :subject.',
        'entry_deleted' => ':actor изтри :value запис от този :subject.',
        'entry_marked_as_spam' => ':actor маркира запис като спам за този :subject.',
        'entry_marked_as_legitimate' => ':actor маркира запис като легитимен за този :subject.',
        'security_flag_added' => ':actor добави флага за сигурност :flag със стойност :flag_value към този :subject.',
        'entries_exported' => ':actor експортира :entry_count от този :subject във файла :filename.',
    ],
    'values' => [
        'entry_count' => '{1} :count запис|[2,*] :count записа',
        'spam' => 'спам',
        'legitimate' => 'легитимно',
    ],
];
