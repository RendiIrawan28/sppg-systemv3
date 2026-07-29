<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('container_collection_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sppg_unit_id')->index();
            $table->string('run_number')->unique();
            $table->unsignedSmallInteger('run_year')->index();
            $table->unsignedInteger('sequence_number');
            $table->date('collection_date')->index();
            $table->string('state', 30)->default('active')->index();
            $table->foreignId('driver_id')->index();
            $table->string('driver_name_snapshot');
            $table->string('kernet_name')->nullable();
            $table->string('vehicle_name')->nullable();
            $table->string('vehicle_plate')->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('returned_at')->nullable()->index();
            $table->unsignedInteger('total_collected')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['sppg_unit_id', 'run_year', 'sequence_number'], 'container_collection_run_sequence_unique');
        });

        Schema::create('container_collection_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sppg_unit_id')->index();
            $table->foreignId('distribution_run_id')->index();
            $table->foreignId('distribution_stop_id')->unique();
            $table->date('delivery_date')->index();
            $table->string('destination_name');
            $table->string('destination_type', 30)->nullable();
            $table->text('address')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();
            $table->unsignedInteger('target_containers');
            $table->unsignedInteger('collected_containers')->default(0);
            $table->unsignedInteger('remaining_containers');
            $table->string('status', 30)->default('pending')->index();
            $table->timestamp('available_at')->index();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('container_collection_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('container_collection_run_id')->index();
            $table->foreignId('container_collection_task_id')->index();
            $table->unsignedInteger('collected_quantity');
            $table->string('status', 30)->index();
            $table->foreignId('collected_by')->index();
            $table->timestamp('collected_at')->index();
            $table->string('photo_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        if (Schema::hasTable('distribution_stops')) {
            DB::table('distribution_stops')
                ->join('distribution_runs', 'distribution_runs.id', '=', 'distribution_stops.distribution_run_id')
                ->whereIn('distribution_stops.status', ['delivered', 'partial'])
                ->where('distribution_stops.containers_sent', '>', 0)
                ->orderBy('distribution_stops.id')
                ->select([
                    'distribution_stops.id as stop_id',
                    'distribution_stops.distribution_run_id',
                    'distribution_stops.destination_name',
                    'distribution_stops.destination_type',
                    'distribution_stops.address',
                    'distribution_stops.contact_name',
                    'distribution_stops.contact_phone',
                    'distribution_stops.containers_sent',
                    'distribution_stops.arrived_at',
                    'distribution_runs.sppg_unit_id',
                    'distribution_runs.distribution_date',
                ])
                ->chunk(200, function ($rows): void {
                    foreach ($rows as $row) {
                        DB::table('container_collection_tasks')->updateOrInsert(
                            ['distribution_stop_id' => $row->stop_id],
                            [
                                'sppg_unit_id' => $row->sppg_unit_id,
                                'distribution_run_id' => $row->distribution_run_id,
                                'delivery_date' => $row->distribution_date,
                                'destination_name' => $row->destination_name,
                                'destination_type' => $row->destination_type,
                                'address' => $row->address,
                                'contact_name' => $row->contact_name,
                                'contact_phone' => $row->contact_phone,
                                'target_containers' => $row->containers_sent,
                                'collected_containers' => 0,
                                'remaining_containers' => $row->containers_sent,
                                'status' => 'pending',
                                'available_at' => $row->arrived_at ?? now(),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ],
                        );
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('container_collection_items');
        Schema::dropIfExists('container_collection_tasks');
        Schema::dropIfExists('container_collection_runs');
    }
};
