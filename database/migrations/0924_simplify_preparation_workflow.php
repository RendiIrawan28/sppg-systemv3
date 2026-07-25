<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preparation_returns', function (Blueprint $table): void {
            $table->string('photo_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('preparation_returns', function (Blueprint $table): void {
            $table->string('photo_path')->nullable(false)->change();
        });
    }
};
