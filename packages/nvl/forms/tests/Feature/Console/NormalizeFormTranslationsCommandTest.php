<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

test('clean v1 forms schema has no legacy json localization column', function (): void {
    expect(Schema::hasColumn('forms', 'translations'))->toBeFalse()
        ->and(Schema::hasTable('forms_i18n'))->toBeTrue();
});
