<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_receipt_item_photos')) {
            return;
        }

        Schema::create('stock_receipt_item_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_receipt_item_id')->constrained()->cascadeOnDelete();
            $table->string('item_name_snapshot');
            $table->string('photo_path');
            $table->string('original_name')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['stock_receipt_id', 'stock_receipt_item_id'], 'srip_receipt_item_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_receipt_item_photos');
    }
};
