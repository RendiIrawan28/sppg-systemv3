<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preparation_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sppg_unit_id')->index();
            $table->foreignId('preparation_session_id')->index();
            $table->foreignId('preparation_session_item_id')->index();
            $table->foreignId('source_inventory_lot_id')->nullable()->index();
            $table->foreignId('destination_inventory_lot_id')->nullable()->index();
            $table->foreignId('ingredient_id')->index();
            $table->string('return_number', 80)->unique();
            $table->date('return_date')->index();
            $table->string('ingredient_name_snapshot');
            $table->string('unit_snapshot', 80);
            $table->decimal('requested_quantity', 14, 4);
            $table->decimal('actual_quantity', 14, 4)->nullable();
            $table->string('condition_status', 30);
            $table->string('warehouse_disposition', 30)->nullable();
            $table->text('reason');
            $table->string('photo_path');
            $table->text('warehouse_notes')->nullable();
            $table->string('status', 40)->default('waiting_warehouse_verification')->index();
            $table->foreignId('returned_by')->index();
            $table->timestamp('submitted_at');
            $table->foreignId('verified_by')->nullable()->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preparation_returns');
    }
};
