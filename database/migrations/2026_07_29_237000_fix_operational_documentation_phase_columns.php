<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, string> */
    private array $tables = [
        'washing_documentations',
        'washing_measurements',
        'cleaning_documentations',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasColumn($tableName, 'phase')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('phase', 30)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasColumn($tableName, 'phase')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->decimal('phase', 14, 4)->nullable()->change();
            });
        }
    }
};
