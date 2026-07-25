<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beneficiary_period_category_totals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('beneficiary_period_id');
            $table->foreignId('beneficiary_period_destination_id');
            $table->foreignId('beneficiary_category_id')->nullable();
            $table->string('beneficiary_category_code_snapshot', 80);
            $table->string('beneficiary_category_name_snapshot');
            $table->string('portion_category', 30)->default('small');
            $table->string('menu_audience', 30)->default('student');
            $table->unsignedInteger('total_beneficiaries')->default(0);
            $table->timestamps();

            $table->unique(
                ['beneficiary_period_destination_id', 'beneficiary_category_id'],
                'beneficiary_period_destination_category_unique',
            );
            $table->index(
                ['beneficiary_period_id', 'beneficiary_category_id'],
                'beneficiary_period_category_total_index',
            );
            $table->foreign('beneficiary_period_id', 'bpct_period_fk')
                ->references('id')->on('beneficiary_periods')->cascadeOnDelete();
            $table->foreign('beneficiary_period_destination_id', 'bpct_destination_fk')
                ->references('id')->on('beneficiary_period_destinations')->cascadeOnDelete();
            $table->foreign('beneficiary_category_id', 'bpct_category_fk')
                ->references('id')->on('beneficiary_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beneficiary_period_category_totals');
    }
};
