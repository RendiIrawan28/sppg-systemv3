<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preparation_sessions', function (Blueprint $table): void {
            $table->text('review_notes')->nullable()->after('notes');
        });
        Schema::table('preparation_session_items', function (Blueprint $table): void {
            $table->string('condition_status', 30)->default('good')->after('waste_quantity');
        });
        Schema::table('preparation_steps', function (Blueprint $table): void {
            $table->string('category', 40)->default('process')->after('preparation_session_id')->index();
            $table->boolean('is_mandatory')->default(true)->after('step_name');
            $table->string('result', 30)->default('pending')->after('is_mandatory')->index();
            $table->timestamp('checked_at')->nullable()->after('completed_at');
            $table->unsignedBigInteger('checked_by')->nullable()->after('checked_at')->index();
            $table->unsignedInteger('sort_order')->default(0)->after('notes');
        });
        Schema::table('preparation_deviations', function (Blueprint $table): void {
            $table->string('category', 50)->default('process')->after('detected_at')->index();
            $table->text('immediate_action')->nullable()->after('description');
            $table->string('photo_path')->nullable()->after('corrective_action');
            $table->unsignedBigInteger('reported_by')->nullable()->after('status')->index();
        });
        Schema::table('preparation_handovers', function (Blueprint $table): void {
            $table->unsignedBigInteger('received_by_user_id')->nullable()->after('received_by_name')->index();
        });

        Schema::create('preparation_documentations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('preparation_session_id')->index();
            $table->string('phase', 30)->index();
            $table->string('photo_path');
            $table->string('caption')->nullable();
            $table->timestamp('captured_at');
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('preparation_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('preparation_session_id')->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('action', 60)->index();
            $table->string('from_state', 30)->nullable();
            $table->string('to_state', 30)->nullable();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('notes')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preparation_histories');
        Schema::dropIfExists('preparation_documentations');
        Schema::table('preparation_handovers', fn (Blueprint $table) => $table->dropColumn('received_by_user_id'));
        Schema::table('preparation_deviations', fn (Blueprint $table) => $table->dropColumn([
            'category', 'immediate_action', 'photo_path', 'reported_by',
        ]));
        Schema::table('preparation_steps', fn (Blueprint $table) => $table->dropColumn([
            'category', 'is_mandatory', 'result', 'checked_at', 'checked_by', 'sort_order',
        ]));
        Schema::table('preparation_session_items', fn (Blueprint $table) => $table->dropColumn('condition_status'));
        Schema::table('preparation_sessions', fn (Blueprint $table) => $table->dropColumn('review_notes'));
    }
};
