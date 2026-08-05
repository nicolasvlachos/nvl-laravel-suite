<?php

declare(strict_types=1);

namespace Nvl\Csv\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Minimal Eloquent record used by query export tests.
 */
final class CsvExportRecord extends Model
{
    protected $table = 'csv_export_records';

    protected $guarded = [];

    public $timestamps = false;
}
