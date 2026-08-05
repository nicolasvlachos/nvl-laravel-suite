<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

test('headless forms package does not register frontend scaffold commands', function (): void {
    $commands = Artisan::all();

    expect($commands)->not->toHaveKey('forms:report-components')
        ->not->toHaveKey('forms:scaffold-public-forms')
        ->toHaveKey('nvl:forms:doctor');
});
