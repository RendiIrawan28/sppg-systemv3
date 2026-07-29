<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_areas', function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_areas', 'template_type')) {
                $table->string('template_type', 40)->nullable()->after('category')->index();
            }
            if (! Schema::hasColumn('cleaning_areas', 'auto_schedule')) {
                $table->boolean('auto_schedule')->default(true)->after('frequency');
            }
            if (! Schema::hasColumn('cleaning_areas', 'scheduled_time')) {
                $table->time('scheduled_time')->nullable()->after('auto_schedule');
            }
        });

        Schema::table('cleaning_sessions', function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_sessions', 'waste_presence')) {
                $table->string('waste_presence', 20)->nullable()->after('after_condition');
            }
            if (! Schema::hasColumn('cleaning_sessions', 'waste_handover_report_id')) {
                $table->unsignedBigInteger('waste_handover_report_id')->nullable()->after('waste_presence')->index();
            }
        });

        foreach (['washing_sessions', 'preparation_sessions'] as $sourceTable) {
            Schema::table($sourceTable, function (Blueprint $table) use ($sourceTable): void {
                if (! Schema::hasColumn($sourceTable, 'waste_handover_report_id')) {
                    $table->unsignedBigInteger('waste_handover_report_id')->nullable()->index();
                }
            });
        }

        Schema::table('waste_handover_reports', function (Blueprint $table): void {
            if (! Schema::hasColumn('waste_handover_reports', 'source_type')) {
                $table->string('source_type', 80)->nullable()->after('division_type')->index();
            }
            if (! Schema::hasColumn('waste_handover_reports', 'source_id')) {
                $table->unsignedBigInteger('source_id')->nullable()->after('source_type')->index();
            }
            if (! Schema::hasColumn('waste_handover_reports', 'source_reference')) {
                $table->string('source_reference')->nullable()->after('source_id');
            }
            if (! Schema::hasColumn('waste_handover_reports', 'document_revision')) {
                $table->string('document_revision', 20)->default('00')->after('report_number');
            }
            if (! Schema::hasColumn('waste_handover_reports', 'effective_date')) {
                $table->date('effective_date')->nullable()->after('report_date');
            }
            if (! Schema::hasColumn('waste_handover_reports', 'handed_over_at')) {
                $table->dateTime('handed_over_at')->nullable()->after('effective_date');
            }
            if (! Schema::hasColumn('waste_handover_reports', 'division_approved_by')) {
                $table->unsignedBigInteger('division_approved_by')->nullable()->after('submitted_at')->index();
            }
            if (! Schema::hasColumn('waste_handover_reports', 'division_approved_at')) {
                $table->dateTime('division_approved_at')->nullable()->after('division_approved_by');
            }
        });

        Schema::table('waste_handover_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('waste_handover_items', 'quantity')) {
                $table->decimal('quantity', 14, 3)->nullable()->after('waste_type');
            }
            if (! Schema::hasColumn('waste_handover_items', 'unit')) {
                $table->string('unit', 50)->nullable()->after('quantity');
            }
        });

        if (Schema::hasColumn('waste_handover_items', 'quantity')) {
            DB::table('waste_handover_items')
                ->whereNull('quantity')
                ->update([
                    'quantity' => DB::raw('weight_kg'),
                    'unit' => 'kg',
                ]);
        }

        if (Schema::hasColumn('cleaning_areas', 'template_type')) {
            DB::table('cleaning_areas')->where(function ($query): void {
                $query->where('code', 'like', '%TOILET%')->orWhere('category', 'toilet');
            })->update(['template_type' => 'toilet']);

            DB::table('cleaning_areas')->where(function ($query): void {
                $query->where('code', 'like', '%PORSI%')->orWhere('category', 'portioning');
            })->update(['template_type' => 'portioning']);

            DB::table('cleaning_areas')->where(function ($query): void {
                $query->where('code', 'like', '%GUDANG%')->orWhereIn('category', ['storage', 'warehouse']);
            })->update(['template_type' => 'warehouse']);

            DB::table('cleaning_areas')->where(function ($query): void {
                $query->where('code', 'like', '%DAPUR%')
                    ->orWhere('code', 'like', '%PRODUKSI%')
                    ->orWhere('category', 'production');
            })->update(['template_type' => 'production']);
        }
    }

    public function down(): void
    {
        Schema::table('waste_handover_items', function (Blueprint $table): void {
            foreach (['quantity', 'unit'] as $column) {
                if (Schema::hasColumn('waste_handover_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('waste_handover_reports', function (Blueprint $table): void {
            foreach ([
                'source_type', 'source_id', 'source_reference', 'document_revision',
                'effective_date', 'handed_over_at', 'division_approved_by', 'division_approved_at',
            ] as $column) {
                if (Schema::hasColumn('waste_handover_reports', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        foreach (['washing_sessions', 'preparation_sessions'] as $sourceTable) {
            if (Schema::hasColumn($sourceTable, 'waste_handover_report_id')) {
                Schema::table($sourceTable, function (Blueprint $table): void {
                    $table->dropColumn('waste_handover_report_id');
                });
            }
        }

        Schema::table('cleaning_sessions', function (Blueprint $table): void {
            foreach (['waste_presence', 'waste_handover_report_id'] as $column) {
                if (Schema::hasColumn('cleaning_sessions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('cleaning_areas', function (Blueprint $table): void {
            foreach (['template_type', 'auto_schedule', 'scheduled_time'] as $column) {
                if (Schema::hasColumn('cleaning_areas', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
