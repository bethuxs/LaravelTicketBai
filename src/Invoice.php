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

        // Optional columns: when not set or set to null/empty, return null (column disabled)
        $optionalColumns = ['signature', 'data', 'territory'];
        if (in_array($internalColumn, $optionalColumns, true)) {
            $value = $columns[$internalColumn] ?? null;
            if ($value === null || $value === '') {
                return null;
            }
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

    /**
     * Get TicketBAI-specific payload (signature, path, territory).
     * When ticketbai_data_key is set, reads from data[key]; otherwise from dedicated columns.
     *
     * @return array{signature?: string|null, path?: string|null, territory?: string|null}
     */
    public static function getTicketBaiPayload(self $model): array
    {
        $key = config('ticketbai.ticketbai_data_key');
        $dataColumn = self::getColumnName('data');

        if ($key !== null && $key !== '' && $dataColumn !== null && $dataColumn !== '') {
            $data = $model->{$dataColumn};
            $pathCol = self::getColumnName('path');
            $pathFromColumn = $pathCol !== null ? ($model->{$pathCol} ?? null) : null;
            if (is_array($data) && isset($data[$key]) && is_array($data[$key])) {
                return [
                    'signature' => $data[$key]['signature'] ?? null,
                    'path' => $pathFromColumn,
                    'territory' => $data[$key]['territory'] ?? null,
                ];
            }

            return ['signature' => null, 'path' => $pathFromColumn, 'territory' => null];
        }

        $payload = ['signature' => null, 'path' => null, 'territory' => null];
        $sigCol = self::getColumnName('signature');
        if ($sigCol !== null) {
            $payload['signature'] = $model->{$sigCol} ?? null;
        }
        $pathCol = self::getColumnName('path');
        if ($pathCol !== null) {
            $payload['path'] = $model->{$pathCol} ?? null;
        }
        $terrCol = self::getColumnName('territory');
        if ($terrCol !== null) {
            $payload['territory'] = $model->{$terrCol} ?? null;
        }

        return $payload;
    }
}
