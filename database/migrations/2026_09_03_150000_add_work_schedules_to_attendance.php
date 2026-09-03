<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_work_schedules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('sppg_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('division_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('late_tolerance_minutes')->default(0);
            $table->json('work_days');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['sppg_unit_id', 'division_id', 'is_active'], 'attendance_schedule_unit_division');
        });
        Schema::create('attendance_work_schedule_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sppg_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_work_schedule_id')->constrained('attendance_work_schedules', 'id', 'attendance_assignment_schedule_fk')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['sppg_unit_id', 'user_id', 'is_active'], 'attendance_assignment_user_active');
        });
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->string('division_name_snapshot', 120)->nullable();
            $table->foreignId('attendance_work_schedule_id')->nullable()->constrained('attendance_work_schedules', 'id', 'attendance_session_schedule_fk')->nullOnDelete();
            $table->string('shift_name_snapshot', 120)->nullable();
            $table->dateTime('scheduled_check_in_at')->nullable();
            $table->dateTime('scheduled_check_out_at')->nullable();
            $table->unsignedSmallInteger('late_tolerance_minutes_snapshot')->nullable();
            $table->unsignedInteger('late_minutes')->default(0);
            $table->string('punctuality_status', 20)->nullable()->index();
            $table->index(['sppg_unit_id', 'user_id', 'work_date'], 'attendance_session_user_work_date');
        });

        // Historical attendance keeps its original times; only known division membership is backfilled.
        DB::table('attendance_sessions')->whereNull('division_name_snapshot')->orderBy('id')->chunkById(500, function ($sessions) {
            foreach ($sessions as $session) {
                $division = DB::table('division_user')->join('divisions', 'divisions.id', '=', 'division_user.division_id')
                    ->where('division_user.user_id', $session->user_id)->where('division_user.sppg_unit_id', $session->sppg_unit_id)
                    ->where('division_user.is_active', true)->where('divisions.is_active', true)
                    ->orderByDesc('division_user.is_primary')->orderBy('divisions.sort_order')->orderBy('divisions.id')
                    ->select('divisions.id', 'divisions.name')->first();
                if ($division) {
                    DB::table('attendance_sessions')->where('id', $session->id)->update(['division_id' => $division->id, 'division_name_snapshot' => $division->name]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->dropForeign(['division_id']);
            $table->dropForeign('attendance_session_schedule_fk');
            $table->dropIndex('attendance_session_user_work_date');
            $table->dropIndex(['punctuality_status']);
            $table->dropColumn(['division_id', 'division_name_snapshot', 'attendance_work_schedule_id', 'shift_name_snapshot', 'scheduled_check_in_at', 'scheduled_check_out_at', 'late_tolerance_minutes_snapshot', 'late_minutes', 'punctuality_status']);
        });
        Schema::dropIfExists('attendance_work_schedule_assignments');
        Schema::dropIfExists('attendance_work_schedules');
    }
};
