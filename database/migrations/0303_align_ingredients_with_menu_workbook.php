<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table): void {
            $table->decimal('reference_price', 14, 2)->nullable()->after('grams_per_unit');
            $table->decimal('loss_factor', 10, 4)->default(1)->after('reference_price');
            $table->decimal('rounding_increment', 14, 4)->nullable()->after('loss_factor');
            $table->string('rounding_mode', 20)->default('up')->after('rounding_increment');
            $table->decimal('nutrition_reference_grams', 10, 4)->default(100)->after('rounding_mode');
            $table->string('nutrition_source', 120)->nullable()->after('nutrition_reference_grams');
            $table->text('specification')->nullable()->after('nutrition_source');
            $table->unsignedInteger('source_row')->nullable()->after('specification');
        });

        Schema::table('nutrition_requirement_items', function (Blueprint $table): void {
            $table->decimal('loss_factor', 10, 4)->default(1)->after('edible_portion_percent');
            $table->decimal('rounding_increment', 14, 4)->nullable()->after('loss_factor');
            $table->decimal('unrounded_quantity', 14, 4)->nullable()->after('rounding_increment');
            $table->decimal('estimated_unit_price', 14, 2)->nullable()->after('unrounded_quantity');
            $table->decimal('estimated_total_price', 16, 2)->nullable()->after('estimated_unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('nutrition_requirement_items', function (Blueprint $table): void {
            $table->dropColumn([
                'loss_factor',
                'rounding_increment',
                'unrounded_quantity',
                'estimated_unit_price',
                'estimated_total_price',
            ]);
        });

        Schema::table('ingredients', function (Blueprint $table): void {
            $table->dropColumn([
                'reference_price',
                'loss_factor',
                'rounding_increment',
                'rounding_mode',
                'nutrition_reference_grams',
                'nutrition_source',
                'specification',
                'source_row',
            ]);
        });
    }
};
