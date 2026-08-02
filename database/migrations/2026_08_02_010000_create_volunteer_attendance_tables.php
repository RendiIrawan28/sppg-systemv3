<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_devices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('sppg_unit_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 50)->unique();
            $table->char('secret_hash', 64);
            $table->string('location', 180)->nullable();
            $table->string('firmware_version', 40)->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('attendance_registration_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('sppg_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('initiated_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 30)->default('pending')->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('completed_at')->nullable();
            $table->string('registered_uid', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('sppg_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('work_date')->index();
            $table->timestamp('check_in_at')->nullable()->index();
            $table->timestamp('check_out_at')->nullable()->index();
            $table->foreignId('check_in_device_id')->nullable()->constrained('attendance_devices')->nullOnDelete();
            $table->foreignId('check_out_device_id')->nullable()->constrained('attendance_devices')->nullOnDelete();
            $table->string('source', 20)->default('rfid')->index();
            $table->string('status', 30)->default('present')->index();
            $table->text('notes')->nullable();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('corrected_at')->nullable();
            $table->timestamps();
            $table->index(['sppg_unit_id', 'work_date', 'status'], 'attendance_unit_date_status_idx');
        });

        Schema::create('attendance_session_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attendance_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 40);
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->text('reason');
            $table->timestamps();
        });

        Schema::create('attendance_taps', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('sppg_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('attendance_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('request_id', 80);
            $table->string('uid_snapshot', 50);
            $table->string('action', 40)->index();
            $table->string('result', 30)->index();
            $table->string('response_message', 255);
            $table->dateTime('tapped_at')->index();
            $table->dateTime('received_at');
            $table->boolean('is_offline')->default(false)->index();
            $table->json('response_payload')->nullable();
            $table->timestamps();
            $table->unique(['attendance_device_id', 'request_id'], 'attendance_device_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_taps');
        Schema::dropIfExists('attendance_session_histories');
        Schema::dropIfExists('attendance_sessions');
        Schema::dropIfExists('attendance_registration_sessions');
        Schema::dropIfExists('attendance_devices');
    }
};
