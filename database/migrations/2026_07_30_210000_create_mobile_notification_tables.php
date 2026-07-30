<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_device_tokens', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('sppg_unit_id')->nullable()->index();
            $table->uuid('installation_id');
            $table->text('fcm_token');
            $table->char('token_hash', 64)->index();
            $table->string('platform', 20)->default('android');
            $table->string('device_name', 150)->nullable();
            $table->string('app_version', 30)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'installation_id'], 'mobile_device_user_installation_unique');
        });

        Schema::create('mobile_tasks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('sppg_unit_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('task_type', 100)->index();
            $table->string('reference_type', 150)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->unsignedSmallInteger('sequence_number')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 20)->default('normal')->index();
            $table->string('channel', 64)->default('sppg_tasks');
            $table->string('screen', 100)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('due_at')->nullable()->index();
            $table->string('status', 30)->default('pending')->index();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('overdue_sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->char('dedupe_key', 64)->unique();
            $table->timestamps();
            $table->index(['reference_type', 'reference_id'], 'mobile_tasks_reference_index');
            $table->index(['user_id', 'status', 'due_at'], 'mobile_tasks_user_status_due_index');
        });

        Schema::create('mobile_notifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('sppg_unit_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('mobile_task_id')->nullable()->index();
            $table->string('notification_type', 100)->index();
            $table->string('title');
            $table->text('body');
            $table->string('channel', 64)->default('sppg_tasks');
            $table->string('screen', 100)->nullable();
            $table->json('payload')->nullable();
            $table->string('delivery_status', 30)->default('pending')->index();
            $table->string('fcm_message_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->char('dedupe_key', 64)->unique();
            $table->timestamps();
            $table->index(['user_id', 'read_at', 'created_at'], 'mobile_notifications_user_read_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_notifications');
        Schema::dropIfExists('mobile_tasks');
        Schema::dropIfExists('mobile_device_tokens');
    }
};
