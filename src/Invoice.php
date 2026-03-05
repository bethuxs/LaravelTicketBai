<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'data' => 'json',
    ];

    /**
     * Allow mass assignment for all attributes.
     * Since column names are dynamic, we can't use $fillable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Get the table name from configuration.
     */
    public function getTable(): string
    {
        $tableName = config('ticketbai.table.name', 'invoices');

        return $tableName ?: 'invoices';
    }

    /**
     * Get the column name for a given internal column.
     */
    public static function getColumnName(string $internalColumn): ?string
    {
        $columns = config('ticketbai.table.columns', []);

        if (in_array($internalColumn, ['signature', 'data'], true) && ! isset($columns[$internalColumn])) {
            return null;
        }

        $columnName = $columns[$internalColumn] ?? $internalColumn;

        if ($columnName === '' || ! is_string($columnName)) {
            return null;
        }

        return $columnName;
    }

    /**
     * Get all column mappings.
     *
     * @return array<string, string|null>
     */
    public static function getColumnMappings(): array
    {
        return config('ticketbai.table.columns', []);
    }
}
