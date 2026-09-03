<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

trait IsolatedAttendanceDatabase
{
    public function setUpIsolatedAttendanceDatabase(): void
    {
        // This suite cannot resolve any production connection and never invokes migrate:fresh.
        config(['database.default' => 'attendance_memory', 'database.connections' => [
            'attendance_memory' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
        ], 'cache.default' => 'array', 'session.driver' => 'array', 'sppg.unit_id' => null, 'firebase.enabled' => false]);
        DB::purge('attendance_memory');
        $connection = DB::connection('attendance_memory');
        if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('Attendance tests require isolated SQLite memory.');
        }
        (require database_path('migrations/0001_01_01_000000_create_foundation_tables.php'))->up();
        Schema::create('sppg_units', function (Blueprint $t) {
            $t->id();
            foreach (['code', 'name', 'slug', 'address', 'phone', 'email', 'head_name'] as $column) {
                $t->string($column)->nullable();
            }
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('divisions', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->string('name');
            $t->text('description')->nullable();
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('division_user', function (Blueprint $t) {
            $t->id();
            $t->foreignId('division_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('sppg_unit_id')->constrained();
            $t->string('position')->nullable();
            $t->boolean('is_primary')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        foreach ([
            '0101_01_01_000000_create_permission_tables.php',
            '2026_08_02_010000_create_volunteer_attendance_tables.php',
            '2026_08_03_010000_add_reset_tracking_to_attendance_sessions.php',
            '2026_08_06_010000_add_check_out_source_to_attendance_sessions.php',
            '2026_09_03_150000_add_work_schedules_to_attendance.php',
            '2026_08_02_011000_assign_attendance_permissions.php',
            '2026_09_03_151000_add_attendance_schedule_permission.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function tearDownIsolatedAttendanceDatabase(): void
    {
        DB::purge('attendance_memory');
    }
}
