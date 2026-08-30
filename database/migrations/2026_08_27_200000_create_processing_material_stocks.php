<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processing_material_stocks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('sppg_unit_id')->index();
            $table->string('source_type', 40)->index();
            $table->unsignedBigInteger('source_id')->nullable()->index();
            $table->unsignedBigInteger('source_item_id')->index();
            $table->unsignedBigInteger('ingredient_id')->nullable()->index();
            $table->unsignedBigInteger('inventory_lot_id')->nullable()->index();
            $table->string('material_name');
            $table->unsignedBigInteger('measurement_unit_id')->nullable()->index();
            $table->string('unit_name', 80);
            $table->decimal('received_quantity', 14, 4);
            $table->decimal('available_quantity', 14, 4);
            $table->string('source_reference')->nullable();
            $table->unsignedBigInteger('received_by')->nullable()->index();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 30)->default('available')->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_type', 'source_item_id'],
                'processing_stock_source_unique',
            );
        });

        Schema::table('processing_material_usages', function (Blueprint $table): void {
            $table->unsignedBigInteger('processing_material_stock_id')
                ->nullable()
                ->after('processing_batch_id')
                ->index();
            $table->unique(
                ['processing_batch_id', 'processing_material_stock_id'],
                'processing_usage_stock_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('processing_material_usages', function (Blueprint $table): void {
            $table->dropUnique('processing_usage_stock_unique');
            $table->dropColumn('processing_material_stock_id');
        });

        Schema::dropIfExists('processing_material_stocks');
    }
};
