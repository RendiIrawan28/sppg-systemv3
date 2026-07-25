<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_shifts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('sppg_unit_id')->index();
            $table->foreignId('officer_id')->index();
            $table->string('officer_name_snapshot');
            $table->dateTime('started_at')->index();
            $table->dateTime('scheduled_end_at')->index();
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->unsignedTinyInteger('reports_expected')->default(4);
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('security_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('security_shift_id')->index();
            $table->foreignId('sppg_unit_id')->index();
            $table->unsignedTinyInteger('sequence_number');
            $table->dateTime('due_at')->index();
            $table->dateTime('reported_at')->index();
            $table->string('situation', 30)->default('safe')->index();
            $table->boolean('gate_secure')->default(true);
            $table->boolean('perimeter_secure')->default(true);
            $table->text('access_activity')->nullable();
            $table->text('visitor_activity')->nullable();
            $table->text('notes')->nullable();
            $table->string('photo_path');
            $table->unsignedBigInteger('created_by')->index();
            $table->timestamps();
            $table->unique(['security_shift_id', 'sequence_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_reports');
        Schema::dropIfExists('security_shifts');
    }
};
