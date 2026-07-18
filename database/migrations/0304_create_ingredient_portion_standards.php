<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_portion_standards', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('sppg_unit_id')->index();
            $table->unsignedBigInteger('ingredient_id')->index();
            $table->unsignedBigInteger('measurement_unit_id')->nullable()->index();
            $table->string('component_type', 40)->nullable()->index();
            $table->decimal('small_quantity', 14, 4);
            $table->decimal('large_quantity', 14, 4);
            $table->decimal('toddler_quantity', 14, 4)->nullable();
            $table->decimal('maternal_quantity', 14, 4)->nullable();
            $table->decimal('grams_per_unit', 14, 4)->default(1);
            $table->string('source', 160)->default('Standar Gizi siswa');
            $table->unsignedInteger('source_row')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['sppg_unit_id', 'ingredient_id'], 'uniq_portion_standard_ingredient');
        });

        Schema::table('recipe_ingredients', function (Blueprint $table): void {
            $table->unsignedBigInteger('ingredient_portion_standard_id')->nullable()->index('idx_recipe_portion_standard');
            $table->string('portion_source', 40)->default('manual');
            $table->boolean('portion_override')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('recipe_ingredients', function (Blueprint $table): void {
            $table->dropIndex('idx_recipe_portion_standard');
            $table->dropColumn(['ingredient_portion_standard_id', 'portion_source', 'portion_override']);
        });

        Schema::dropIfExists('ingredient_portion_standards');
    }
};
