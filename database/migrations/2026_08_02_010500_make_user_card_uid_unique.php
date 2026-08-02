<?php

use App\Services\VolunteerAttendanceService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNotNull('employee_number')->orderBy('id')->each(function ($user): void {
            DB::table('users')->where('id', $user->id)->update(['employee_number' => VolunteerAttendanceService::normalizeUid($user->employee_number)]);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['employee_number']);
            $table->unique('employee_number');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['employee_number']);
            $table->index('employee_number');
        });
    }
};
