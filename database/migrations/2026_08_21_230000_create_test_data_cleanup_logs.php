<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_data_cleanup_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sppg_unit_id')->index();
            $table->foreignId('actor_id')->index();
            $table->string('actor_name_snapshot');
            $table->string('record_type', 80)->index();
            $table->string('record_label');
            $table->string('source_table', 100);
            $table->unsignedBigInteger('source_id');
            $table->string('source_number')->nullable();
            $table->text('reason');
            $table->longText('record_snapshot')->nullable();
            $table->json('deleted_counts')->nullable();
            $table->timestamp('deleted_at')->index();
            $table->timestamps();

            $table->index(['record_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_data_cleanup_logs');
    }
};
