<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_cycle_day_variants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('menu_cycle_day_id')->index();
            $table->string('audience_type', 30);
            $table->unsignedBigInteger('menu_id')->index();
            $table->timestamps();

            $table->unique(
                ['menu_cycle_day_id', 'audience_type'],
                'menu_day_variant_unique',
            );
            $table->foreign('menu_cycle_day_id', 'menu_day_variant_day_fk')
                ->references('id')->on('menu_cycle_days')->cascadeOnDelete();
            $table->foreign('menu_id', 'menu_day_variant_menu_fk')
                ->references('id')->on('menus')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_cycle_day_variants');
    }
};
