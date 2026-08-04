<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_stocks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('sppg_unit_id')->constrained()->cascadeOnDelete();
            $table->string('opening_number', 80)->index();
            $table->date('opening_date')->index();
            $table->string('photo_path');
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['sppg_unit_id', 'opening_number']);
        });

        Schema::create('opening_stock_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opening_stock_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_lot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ingredient_name_snapshot');
            $table->string('unit_snapshot', 80);
            $table->decimal('quantity', 14, 4);
            $table->string('lot_number', 100)->nullable();
            $table->date('expired_date')->nullable();
            $table->string('storage_type', 30)->index();
            $table->string('location_name')->nullable();
            $table->text('condition_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_stock_items');
        Schema::dropIfExists('opening_stocks');
    }
};
