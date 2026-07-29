<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('washing_sessions', function (Blueprint $table): void {
            if (! Schema::hasColumn('washing_sessions', 'distribution_expected_containers')) {
                $table->unsignedInteger('distribution_expected_containers')->default(0)->after('menu_name_snapshot');
            }
            if (! Schema::hasColumn('washing_sessions', 'distribution_returned_containers')) {
                $table->unsignedInteger('distribution_returned_containers')->default(0)->after('distribution_expected_containers');
            }
            if (! Schema::hasColumn('washing_sessions', 'distribution_damaged_containers')) {
                $table->unsignedInteger('distribution_damaged_containers')->default(0)->after('distribution_returned_containers');
            }
            if (! Schema::hasColumn('washing_sessions', 'distribution_lost_containers')) {
                $table->unsignedInteger('distribution_lost_containers')->default(0)->after('distribution_damaged_containers');
            }
            if (! Schema::hasColumn('washing_sessions', 'receiving_difference')) {
                $table->integer('receiving_difference')->default(0)->after('missing_containers');
            }
            if (! Schema::hasColumn('washing_sessions', 'has_food_waste')) {
                $table->boolean('has_food_waste')->nullable()->after('receiving_difference');
            }
            if (! Schema::hasColumn('washing_sessions', 'no_waste_confirmed')) {
                $table->boolean('no_waste_confirmed')->default(false)->after('has_food_waste');
            }
            if (! Schema::hasColumn('washing_sessions', 'waste_recorded_at')) {
                $table->timestamp('waste_recorded_at')->nullable()->after('no_waste_confirmed');
            }
            if (! Schema::hasColumn('washing_sessions', 'waste_handed_over_at')) {
                $table->timestamp('waste_handed_over_at')->nullable()->after('waste_recorded_at');
            }
            if (! Schema::hasColumn('washing_sessions', 'waste_first_party_name')) {
                $table->string('waste_first_party_name')->nullable()->after('waste_handed_over_at');
            }
            if (! Schema::hasColumn('washing_sessions', 'waste_first_party_position')) {
                $table->string('waste_first_party_position')->nullable()->after('waste_first_party_name');
            }
            if (! Schema::hasColumn('washing_sessions', 'waste_first_party_address')) {
                $table->text('waste_first_party_address')->nullable()->after('waste_first_party_position');
            }
            if (! Schema::hasColumn('washing_sessions', 'waste_second_party_name')) {
                $table->string('waste_second_party_name')->nullable()->after('waste_first_party_address');
            }
            if (! Schema::hasColumn('washing_sessions', 'waste_second_party_position')) {
                $table->string('waste_second_party_position')->nullable()->after('waste_second_party_name');
            }
            if (! Schema::hasColumn('washing_sessions', 'waste_second_party_address')) {
                $table->text('waste_second_party_address')->nullable()->after('waste_second_party_position');
            }
            if (! Schema::hasColumn('washing_sessions', 'waste_handover_notes')) {
                $table->text('waste_handover_notes')->nullable()->after('waste_second_party_address');
            }
        });

        // Menjaga sesi lama tetap dapat dibuka setelah alur baru diterapkan.
        DB::table('washing_sessions')->orderBy('id')->chunkById(200, function ($sessions): void {
            foreach ($sessions as $session) {
                $hasWaste = DB::table('washing_waste_records')
                    ->where('washing_session_id', $session->id)
                    ->exists();

                $updates = [
                    'distribution_expected_containers' => (int) ($session->expected_containers ?? 0),
                    'distribution_returned_containers' => max(
                        0,
                        (int) ($session->expected_containers ?? 0)
                            - (int) ($session->damaged_containers ?? 0)
                            - (int) ($session->missing_containers ?? 0),
                    ),
                    'distribution_damaged_containers' => (int) ($session->damaged_containers ?? 0),
                    'distribution_lost_containers' => (int) ($session->missing_containers ?? 0),
                    'receiving_difference' => (int) ($session->received_containers ?? 0)
                        - (int) ($session->expected_containers ?? 0),
                ];

                if ($session->state !== 'planned') {
                    $updates += [
                        'has_food_waste' => $hasWaste,
                        'no_waste_confirmed' => ! $hasWaste,
                        'waste_recorded_at' => $session->updated_at ?? now(),
                        'waste_handed_over_at' => $session->updated_at ?? now(),
                    ];
                }

                DB::table('washing_sessions')->where('id', $session->id)->update($updates);
            }
        });
    }

    public function down(): void
    {
        $columns = [
            'distribution_expected_containers',
            'distribution_returned_containers',
            'distribution_damaged_containers',
            'distribution_lost_containers',
            'receiving_difference',
            'has_food_waste',
            'no_waste_confirmed',
            'waste_recorded_at',
            'waste_handed_over_at',
            'waste_first_party_name',
            'waste_first_party_position',
            'waste_first_party_address',
            'waste_second_party_name',
            'waste_second_party_position',
            'waste_second_party_address',
            'waste_handover_notes',
        ];

        $existing = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn('washing_sessions', $column),
        ));

        if ($existing !== []) {
            Schema::table('washing_sessions', function (Blueprint $table) use ($existing): void {
                $table->dropColumn($existing);
            });
        }
    }
};
