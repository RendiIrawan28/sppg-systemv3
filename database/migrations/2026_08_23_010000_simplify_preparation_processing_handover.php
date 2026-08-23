<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preparation_documentations', function (Blueprint $table): void {
            $table->dropUnique('preparation_documentation_session_unique');
            $table->foreignId('preparation_session_item_id')->nullable()->after('preparation_session_id')->index();
        });
        Schema::table('preparation_session_items', function (Blueprint $table): void {
            $table->string('output_target_division', 30)->default('processing');
        });

        // Preserve the old session photo by associating it with the first item.
        DB::table('preparation_documentations')->orderBy('id')->get()->each(function ($photo): void {
            $itemId = DB::table('preparation_session_items')
                ->where('preparation_session_id', $photo->preparation_session_id)
                ->orderBy('id')
                ->value('id');
            if ($itemId) {
                DB::table('preparation_documentations')->where('id', $photo->id)
                    ->update(['preparation_session_item_id' => $itemId]);
            }
        });

        Schema::table('preparation_documentations', function (Blueprint $table): void {
            $table->unique('preparation_session_item_id', 'prep_doc_item_unique');
        });

        Schema::table('processing_batches', function (Blueprint $table): void {
            $table->foreignId('portioning_session_id')->nullable()->index();
            $table->timestamp('portioning_handed_over_at')->nullable()->index();
            $table->foreignId('portioning_handed_over_by')->nullable()->index();
            $table->timestamp('portioning_received_at')->nullable()->index();
            $table->foreignId('portioning_received_by')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('processing_batches', function (Blueprint $table): void {
            $table->dropColumn([
                'portioning_session_id', 'portioning_handed_over_at', 'portioning_handed_over_by',
                'portioning_received_at', 'portioning_received_by',
            ]);
        });

        Schema::table('preparation_documentations', function (Blueprint $table): void {
            $table->dropUnique('prep_doc_item_unique');
            $table->dropColumn('preparation_session_item_id');
            $table->unique('preparation_session_id', 'preparation_documentation_session_unique');
        });

        Schema::table('preparation_session_items', function (Blueprint $table): void {
            $table->dropColumn('output_target_division');
        });
    }
};
