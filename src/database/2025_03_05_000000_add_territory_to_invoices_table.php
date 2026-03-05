<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('ticketbai.table.name', 'invoices');
        Schema::table($tableName, function (Blueprint $table) {
            $table->string('territory', 20)->nullable()->after('number');
        });
    }

    public function down(): void
    {
        $tableName = config('ticketbai.table.name', 'invoices');
        if (Schema::hasColumn($tableName, 'territory')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('territory');
            });
        }
    }
};
