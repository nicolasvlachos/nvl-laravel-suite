<?php

declare(strict_types=1);

return [
    'templates' => [
        'duplicated' => ':actor duplicated this :subject.',
        'submission_received' => ':actor recorded a new :value submission for this :subject.',
        'entry_deleted' => ':actor deleted a :value entry from this :subject.',
        'entry_marked_as_spam' => ':actor marked an entry as spam for this :subject.',
        'entry_marked_as_legitimate' => ':actor marked an entry as legitimate for this :subject.',
        'security_flag_added' => ':actor added the security flag :flag with value :flag_value to this :subject.',
        'entries_exported' => ':actor exported :entry_count from this :subject to :filename.',
    ],
    'values' => [
        'entry_count' => '{1} :count entry|[2,*] :count entries',
        'spam' => 'spam',
        'legitimate' => 'legitimate',
    ],
];
