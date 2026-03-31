<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddStatusToInvoices extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tableName = config('ticketbai.table.name', 'invoices');
        
        Schema::table($tableName ?: 'invoices', function (Blueprint $table) {
            // Only add the status column if it doesn't already exist
            if (!Schema::hasColumn($tableName ?: 'invoices', 'status')) {
                $table->string('status', 20)->nullable()->after('sent');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableName = config('ticketbai.table.name', 'invoices');
        
        Schema::table($tableName ?: 'invoices', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
}
