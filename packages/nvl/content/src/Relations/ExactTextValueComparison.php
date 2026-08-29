<?php

declare(strict_types=1);

namespace Nvl\Content\Relations;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * Internal byte-exact expression comparing one wrapped text column with a value.
 */
final readonly class ExactTextValueComparison implements Expression
{
    public function __construct(
        private string $column,
        private string $driver,
    ) {}

    public function getValue(Grammar $grammar): string
    {
        return match ($this->driver) {
            'mysql', 'mariadb' => "BINARY {$this->column} = BINARY ? ".
                "AND OCTET_LENGTH({$this->column}) = OCTET_LENGTH(?)",
            'pgsql' => "{$this->column} COLLATE \"C\" = CAST(? AS TEXT) COLLATE \"C\" ".
                "AND OCTET_LENGTH({$this->column}) = OCTET_LENGTH(CAST(? AS TEXT))",
            'sqlsrv' => "{$this->column} COLLATE Latin1_General_100_BIN2 = ".
                'CAST(? AS NVARCHAR(255)) COLLATE Latin1_General_100_BIN2 '.
                "AND DATALENGTH({$this->column}) = DATALENGTH(CAST(? AS NVARCHAR(255)))",
            default => "{$this->column} = ? COLLATE BINARY ".
                "AND LENGTH(CAST({$this->column} AS BLOB)) = LENGTH(CAST(? AS BLOB))",
        };
    }
}
