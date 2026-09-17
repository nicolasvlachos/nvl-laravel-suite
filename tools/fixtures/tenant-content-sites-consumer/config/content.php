<?php

declare(strict_types=1);

return [
    'authorization' => ['callback' => static fn (): bool => true],
    'locales' => ['available' => ['en'], 'required_on_publish' => ['en']],
    'scopes' => ['site' => ['key_pattern' => '/^[a-z0-9-]{1,50}$/']],
];
