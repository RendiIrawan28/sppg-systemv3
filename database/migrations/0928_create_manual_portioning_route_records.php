<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portioning_route_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('portioning_session_id')->index();
            $table->string('route_name');
            $table->unsignedInteger('small_portions')->default(0);
            $table->unsignedInteger('large_portions')->default(0);
            $table->string('photo_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['portioning_session_id', 'route_name'],
                'portioning_route_record_session_name_unique',
            );
        });

        $groups = DB::table('portioning_route_allocations')
            ->whereNotNull('portioned_at')
            ->orderBy('portioning_session_id')
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($row): string => $row->portioning_session_id.'|'.trim((string) $row->route_name));

        foreach ($groups as $group) {
            $first = $group->first();
            $routeName = trim((string) $first->route_name) ?: 'Rute';
            $documentation = DB::table('portioning_documentations')
                ->where('portioning_session_id', $first->portioning_session_id)
                ->where('phase', 'route')
                ->where('caption', $routeName)
                ->first();

            DB::table('portioning_route_records')->insert([
                'portioning_session_id' => $first->portioning_session_id,
                'route_name' => $routeName,
                'small_portions' => (int) $group->sum('actual_small_portions'),
                'large_portions' => (int) $group->sum('actual_large_portions'),
                'photo_path' => $documentation?->photo_path,
                'notes' => $group->pluck('notes')->filter()->unique()->implode('; ') ?: null,
                'completed_at' => $group->max('portioned_at'),
                'created_by' => $documentation?->created_by,
                'updated_by' => $documentation?->created_by,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('portioning_route_records');
    }
};
