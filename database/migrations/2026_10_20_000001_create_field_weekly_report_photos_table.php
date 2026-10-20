<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_weekly_report_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sppg_unit_id')->constrained()->cascadeOnDelete();
            $table->date('photo_date');
            $table->string('photo_type', 24);
            $table->string('photo_path', 255);
            $table->string('caption', 255)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['sppg_unit_id', 'photo_date', 'photo_type'], 'weekly_photo_unit_date_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_weekly_report_photos');
    }
};
