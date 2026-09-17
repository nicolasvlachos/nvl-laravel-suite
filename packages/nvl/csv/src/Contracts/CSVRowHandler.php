<?php

declare(strict_types=1);

namespace Nvl\Csv\Contracts;

interface CSVRowHandler
{
    /** @param array<string,mixed> $row */
    public function process(array $row, int $rowNumber): void;
}
