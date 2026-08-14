<?php

declare(strict_types=1);

namespace Nvl\Csv\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Nvl\Csv\ValueObjects\CSVImportResult;
use Throwable;

/**
 * Owns connection-specific import and batch transaction boundaries.
 *
 * @internal
 */
final readonly class CSVTransactionRunner
{
    /**
     * Execute an import and commit only a fully successful result.
     *
     * @param  Closure(): CSVImportResult  $operation
     */
    public function import(?string $connection, Closure $operation): CSVImportResult
    {
        $database = DB::connection($connection);
        $transactionStarted = false;

        try {
            $database->beginTransaction();
            $transactionStarted = true;
            $result = $operation();

            if ($result->isSuccessful()) {
                $database->commit();
            } else {
                $database->rollBack();
            }

            $transactionStarted = false;

            return $result;
        } catch (Throwable $exception) {
            if ($transactionStarted) {
                $database->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Execute one batch on the selected import connection.
     *
     * @param  Closure(): void  $operation
     */
    public function batch(?string $connection, Closure $operation): void
    {
        DB::connection($connection)->transaction($operation);
    }
}
