<?php

declare(strict_types=1);

namespace Nvl\Csv\Tests\Type;

use Illuminate\Database\Eloquent\Builder;
use Nvl\Csv\Services\CSVExport;
use Nvl\Csv\Tests\Fixtures\CsvExportRecord;
use Nvl\Csv\ValueObjects\CSVExportResult;

/**
 * Proves concrete model builders remain accepted without erasing their template type.
 *
 * @param  Builder<CsvExportRecord>  $records
 */
function exportConcreteRecords(CSVExport $export, Builder $records): CSVExportResult
{
    return $export->fromQuery($records);
}
