<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Nvl\Forms\Definitions\Tables\FormsTables;

test('clean v1 forms schema has no legacy json localization column', function (): void {
    expect(Schema::hasColumn(FormsTables::Forms, 'translations'))->toBeFalse()
        ->and(Schema::hasTable(FormsTables::I18n))->toBeTrue();
});
