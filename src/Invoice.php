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
        if (! is_array($columns)) {
            $columns = [];
        }

        // Columns that can be disabled (column name set to null)
        $optionalColumns = ['data', 'status'];
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
        $columns = config('ticketbai.table.columns', []);
        if (! is_array($columns)) {
            return [];
        }
        return $columns;
    }

    /**
     * Get the validated TicketBAI data key (for data[key]). Throws if key is empty.
     */
    public static function getTicketBaiDataKey(): string
    {
        $key = config('ticketbai.data_key', 'ticketbai');
        if ($key === null || $key === '') {
            throw Exceptions\InvalidConfigurationException::emptyDataKey();
        }

        return $key;
    }

    /**
     * Get TicketBAI payload (signature, path, territory).
     * Signature and territory from data[key]; path from path column.
     *
     * @return array{signature?: string|null, path?: string|null, territory?: string|null}
     */
    public static function getTicketBaiPayload(self $model): array
    {
        $key = self::getTicketBaiDataKey();
        $dataColumn = self::getColumnName('data') ?? 'data';
        $pathCol = self::getColumnName('path');
        $pathFromColumn = $pathCol !== null ? ($model->{$pathCol} ?? null) : null;

        $data = $model->{$dataColumn} ?? null;
        if (! is_array($data)) {
            return [
                'signature' => null,
                'path' => $pathFromColumn,
                'territory' => null,
            ];
        }

        $payload = isset($data[$key]) && is_array($data[$key])
            ? $data[$key]
            : [];

        return [
            'signature' => $payload['signature'] ?? $data['signature'] ?? null,
            'path' => $pathFromColumn,
            'territory' => $payload['territory'] ?? $data['territory'] ?? null,
        ];
    }

    /**
     * Merge send metadata without replacing the namespaced TicketBAI payload.
     *
     * This also restores the namespaced payload for rows flattened by older
     * InvoiceSend versions so that retries and resends remain possible.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function mergeTicketBaiMetadata(self $model, array $metadata): void
    {
        $dataColumn = self::getColumnName('data');
        if ($dataColumn === null) {
            return;
        }

        $data = $model->{$dataColumn} ?? [];
        if (! is_array($data)) {
            $data = [];
        }

        $key = self::getTicketBaiDataKey();
        $ticketBaiData = isset($data[$key]) && is_array($data[$key])
            ? $data[$key]
            : [];
        $payload = self::getTicketBaiPayload($model);

        foreach (['signature', 'territory'] as $field) {
            if (! array_key_exists($field, $ticketBaiData) && $payload[$field] !== null) {
                $ticketBaiData[$field] = $payload[$field];
            }
        }

        if ($ticketBaiData !== []) {
            $data[$key] = $ticketBaiData;
        }

        $model->{$dataColumn} = array_replace($data, $metadata);
    }
}
