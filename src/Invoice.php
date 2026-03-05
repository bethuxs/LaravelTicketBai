<?php
namespace EBethus\LaravelTicketBAI;

use Illuminate\Database\Eloquent\Model;


class Invoice extends Model
{
    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'data' => 'json',
    ];

    /**
     * Allow mass assignment for all attributes
     * Since column names are dynamic, we can't use $fillable
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * Get the table name from configuration
     *
     * @return string
     */
    public function getTable()
    {
        $tableName = config('ticketbai.table.name', 'invoices');
        return $tableName;
    }

    /**
     * Get the column name for a given internal column
     *
     * @param string $internalColumn
     * @return string
     */
    public static function getColumnName($internalColumn)
    {
        $columns = config('ticketbai.table.columns', []);
        
        // For signature and data, default to null if not configured
        if (in_array($internalColumn, ['signature', 'data']) && !isset($columns[$internalColumn])) {
            return null;
        }
        
        $columnName = $columns[$internalColumn] ?? $internalColumn;
        // Return null if column is explicitly set to null or empty string
        return ($columnName === null || $columnName === '') ? null : $columnName;
    }

    /**
     * Get all column mappings
     *
     * @return array
     */
    public static function getColumnMappings()
    {
        return config('ticketbai.table.columns', []);
    }
}