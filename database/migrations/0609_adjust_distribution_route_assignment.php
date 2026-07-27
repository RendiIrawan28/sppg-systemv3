<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('distribution_runs')) {
            return;
        }

        Schema::table('distribution_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('distribution_runs', 'route_name')) {
                $table->string('route_name')->nullable()->after('field_distribution_plan_id');
            }

            if (! Schema::hasColumn('distribution_runs', 'assigned_at')) {
                $table->timestamp('assigned_at')->nullable()->after('planned_departure_at');
            }

            if (! Schema::hasColumn('distribution_runs', 'loading_started_at')) {
                $table->timestamp('loading_started_at')->nullable()->after('assigned_at');
            }

            if (! Schema::hasColumn('distribution_runs', 'destinations_completed_at')) {
                $table->timestamp('destinations_completed_at')->nullable()->after('actual_departure_at');
            }

            if (! Schema::hasColumn('distribution_runs', 'containers_returned')) {
                $table->unsignedInteger('containers_returned')->default(0)->after('returned_large_portions');
            }

            if (! Schema::hasColumn('distribution_runs', 'containers_damaged')) {
                $table->unsignedInteger('containers_damaged')->default(0)->after('containers_returned');
            }

            if (! Schema::hasColumn('distribution_runs', 'containers_lost')) {
                $table->unsignedInteger('containers_lost')->default(0)->after('containers_damaged');
            }
        });

        DB::table('distribution_runs')
            ->whereNull('route_name')
            ->orderBy('id')
            ->chunkById(100, function ($runs): void {
                foreach ($runs as $run) {
                    $routeName = DB::table('distribution_stops')
                        ->where('distribution_run_id', $run->id)
                        ->whereNotNull('route_name')
                        ->where('route_name', '!=', '')
                        ->orderBy('sequence_order')
                        ->orderBy('id')
                        ->value('route_name');

                    DB::table('distribution_runs')
                        ->where('id', $run->id)
                        ->update([
                            'route_name' => $routeName ?: 'Rute Utama',
                        ]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('distribution_runs')) {
            return;
        }

        Schema::table('distribution_runs', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('distribution_runs', 'route_name') ? 'route_name' : null,
                Schema::hasColumn('distribution_runs', 'assigned_at') ? 'assigned_at' : null,
                Schema::hasColumn('distribution_runs', 'loading_started_at') ? 'loading_started_at' : null,
                Schema::hasColumn('distribution_runs', 'destinations_completed_at') ? 'destinations_completed_at' : null,
                Schema::hasColumn('distribution_runs', 'containers_returned') ? 'containers_returned' : null,
                Schema::hasColumn('distribution_runs', 'containers_damaged') ? 'containers_damaged' : null,
                Schema::hasColumn('distribution_runs', 'containers_lost') ? 'containers_lost' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
