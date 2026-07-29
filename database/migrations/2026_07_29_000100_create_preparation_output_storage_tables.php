<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preparation_outputs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sppg_unit_id')->index();
            $table->foreignId('preparation_session_id')->index();
            $table->foreignId('preparation_session_item_id')->nullable()->index();
            $table->foreignId('ingredient_id')->nullable()->index();
            $table->string('output_name');
            $table->string('source_ingredient_name_snapshot')->nullable();
            $table->decimal('quantity', 14, 4);
            $table->decimal('available_quantity', 14, 4);
            $table->string('unit_snapshot', 80);
            $table->string('target_division', 30)->default('processing')->index();
            $table->string('storage_location')->nullable();
            $table->timestamp('stored_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->string('state', 30)->default('available')->index();
            $table->string('photo_path')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->index();
            $table->foreignId('updated_by')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('preparation_output_withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('preparation_output_id')->index();
            $table->string('destination_division', 30)->index();
            $table->foreignId('processing_batch_id')->nullable()->index();
            $table->foreignId('portioning_session_id')->nullable()->index();
            $table->decimal('requested_quantity', 14, 4);
            $table->decimal('verified_quantity', 14, 4)->nullable();
            $table->string('unit_snapshot', 80);
            $table->string('status', 30)->default('waiting_verification')->index();
            $table->foreignId('taken_by')->index();
            $table->timestamp('taken_at')->index();
            $table->foreignId('verified_by')->nullable()->index();
            $table->timestamp('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preparation_output_withdrawals');
        Schema::dropIfExists('preparation_outputs');
    }
};
