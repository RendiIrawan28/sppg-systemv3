<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('processing_documentations')) {
            return;
        }

        Schema::table('processing_documentations', function (Blueprint $table): void {
            if (! Schema::hasColumn('processing_documentations', 'output_quantity')) {
                $table->decimal('output_quantity', 14, 4)
                    ->nullable();
            }

            if (! Schema::hasColumn('processing_documentations', 'output_unit')) {
                $table->string('output_unit', 80)
                    ->nullable();
            }
        });

        if (! Schema::hasTable('processing_batches')) {
            return;
        }

        DB::table('processing_documentations as documentation')
            ->join('processing_batches as batch', 'batch.id', '=', 'documentation.processing_batch_id')
            ->where('documentation.documentation_type', 'finished_output')
            ->select([
                'documentation.id as documentation_id',
                'documentation.output_quantity',
                'documentation.output_unit',
                'batch.actual_output_quantity',
                'batch.actual_output_unit',
                'batch.target_output_unit',
            ])
            ->orderBy('documentation.id')
            ->get()
            ->each(function (object $row): void {
                DB::table('processing_documentations')
                    ->where('id', $row->documentation_id)
                    ->update([
                        'output_quantity' => $row->output_quantity
                            ?? $row->actual_output_quantity,
                        'output_unit' => $row->output_unit
                            ?: ($row->actual_output_unit ?: $row->target_output_unit),
                    ]);
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('processing_documentations')) {
            return;
        }

        Schema::table('processing_documentations', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('processing_documentations', 'output_quantity')
                    ? 'output_quantity'
                    : null,
                Schema::hasColumn('processing_documentations', 'output_unit')
                    ? 'output_unit'
                    : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
