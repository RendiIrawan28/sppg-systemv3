<?php

namespace App\Services;

use App\Models\TestDataCleanupLog;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class ModuleDataResetService
{
    public const WAREHOUSE = 'warehouse';

    public const BENEFICIARIES = 'beneficiaries';

    public const LABELS = [
        self::WAREHOUSE => 'Data Gudang',
        self::BENEFICIARIES => 'Data Penerima Manfaat',
    ];

    /** @return array{counts: array<string, int>, blockers: array<string, int>, missing: array<int, string>} */
    public function preview(string $scope, int $unitId): array
    {
        $this->assertScope($scope);
        $tables = array_keys($this->queries($scope, $unitId));
        $blockerQueries = $this->blockers($scope, $unitId);
        $missing = array_values(array_filter(
            array_unique([...$tables, ...array_map(fn (Builder $query) => $query->from, $blockerQueries)]),
            fn (string $table): bool => ! Schema::hasTable($table),
        ));

        if ($missing !== []) {
            return ['counts' => [], 'blockers' => [], 'missing' => $missing];
        }

        $counts = [];
        foreach ($this->queries($scope, $unitId) as $table => $query) {
            $counts[$table] = $query->count();
        }
        $blockers = [];
        foreach ($blockerQueries as $label => $query) {
            $count = $query->count();
            if ($count > 0) {
                $blockers[$label] = $count;
            }
        }

        return compact('counts', 'blockers', 'missing');
    }

    /** @return array<string, int> */
    public function reset(string $scope, int $unitId, User $actor, string $reason): array
    {
        abort_unless($actor->is_super_admin, 403);
        $this->assertScope($scope);
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['resetReason' => 'Alasan minimal 10 karakter.']);
        }

        return DB::transaction(function () use ($scope, $unitId, $actor, $reason): array {
            $preview = $this->preview($scope, $unitId);
            if ($preview['missing'] !== []) {
                throw ValidationException::withMessages(['reset' => 'Struktur tabel belum lengkap: '.implode(', ', $preview['missing']).'.']);
            }
            if ($preview['blockers'] !== []) {
                throw ValidationException::withMessages(['reset' => 'Reset ditolak karena masih ada data modul lain yang terhubung: '.implode(', ', array_keys($preview['blockers'])).'. Bersihkan keterkaitan tersebut lebih dahulu.']);
            }
            if (array_sum($preview['counts']) === 0) {
                throw ValidationException::withMessages(['reset' => 'Tidak ada data untuk direset.']);
            }

            $deleted = [];
            foreach ($this->queries($scope, $unitId) as $table => $query) {
                $deleted[$table] = $query->delete();
            }

            TestDataCleanupLog::query()->create([
                'sppg_unit_id' => $unitId,
                'actor_id' => $actor->getKey(),
                'actor_name_snapshot' => $actor->name,
                'record_type' => 'module-reset-'.$scope,
                'record_label' => self::LABELS[$scope],
                'source_table' => 'multiple',
                'source_id' => 0,
                'source_number' => 'RESET MODUL',
                'reason' => trim($reason),
                'record_snapshot' => json_encode(['counts_before' => $preview['counts']], JSON_UNESCAPED_UNICODE),
                'deleted_counts' => $deleted,
                'deleted_at' => now(),
            ]);

            return $deleted;
        });
    }

    private function assertScope(string $scope): void
    {
        abort_unless(isset(self::LABELS[$scope]), 404);
    }

    /**
     * Urutan anak sebelum induk menjaga foreign key. Master barang, supplier,
     * sekolah, posyandu, kategori, akun, dan gudang fisik tidak ikut dihapus.
     *
     * @return array<string, Builder>
     */
    private function queries(string $scope, int $unitId): array
    {
        $unit = fn (string $table): Builder => DB::table($table)->where('sppg_unit_id', $unitId);
        $child = fn (string $table, string $foreignKey, string $parent): Builder => DB::table($table)
            ->whereIn($foreignKey, $unit($parent)->select('id'));

        if ($scope === self::WAREHOUSE) {
            return [
                'stock_receipt_item_photos' => $child('stock_receipt_item_photos', 'stock_receipt_id', 'stock_receipts'),
                'warehouse_withdrawal_items' => $child('warehouse_withdrawal_items', 'warehouse_withdrawal_id', 'warehouse_withdrawals'),
                'opening_stock_items' => $child('opening_stock_items', 'opening_stock_id', 'opening_stocks'),
                'preparation_material_handover_items' => $child('preparation_material_handover_items', 'preparation_material_handover_id', 'preparation_material_handovers'),
                'stock_movements' => $unit('stock_movements'),
                'stock_adjustments' => $unit('stock_adjustments'),
                'inventory_lots' => $unit('inventory_lots'),
                'stock_receipt_items' => $child('stock_receipt_items', 'stock_receipt_id', 'stock_receipts'),
                'stock_receipts' => $unit('stock_receipts'),
                'opening_stocks' => $unit('opening_stocks'),
                'warehouse_withdrawals' => $unit('warehouse_withdrawals'),
                'preparation_material_handovers' => $unit('preparation_material_handovers'),
            ];
        }

        return [
            'daily_beneficiary_confirmation_items' => $child('daily_beneficiary_confirmation_items', 'daily_beneficiary_confirmation_id', 'daily_beneficiary_confirmations'),
            'beneficiary_period_category_totals' => $child('beneficiary_period_category_totals', 'beneficiary_period_id', 'beneficiary_periods'),
            'beneficiary_period_members' => $child('beneficiary_period_members', 'beneficiary_period_id', 'beneficiary_periods'),
            'beneficiary_period_items' => $child('beneficiary_period_items', 'beneficiary_period_id', 'beneficiary_periods'),
            'beneficiary_period_destinations' => $child('beneficiary_period_destinations', 'beneficiary_period_id', 'beneficiary_periods'),
            'beneficiary_period_histories' => $child('beneficiary_period_histories', 'beneficiary_period_id', 'beneficiary_periods'),
            'beneficiary_allergen' => $child('beneficiary_allergen', 'beneficiary_id', 'beneficiaries'),
            'daily_beneficiary_confirmations' => $unit('daily_beneficiary_confirmations'),
            'beneficiary_periods' => $unit('beneficiary_periods'),
            'beneficiaries' => $unit('beneficiaries'),
            'beneficiary_imports' => $unit('beneficiary_imports'),
        ];
    }

    /** @return array<string, Builder> */
    private function blockers(string $scope, int $unitId): array
    {
        if ($scope === self::BENEFICIARIES) {
            $periods = fn (): Builder => DB::table('beneficiary_periods')->where('sppg_unit_id', $unitId)->select('id');
            $confirmations = fn (): Builder => DB::table('daily_beneficiary_confirmations')->where('sppg_unit_id', $unitId)->select('id');

            return [
                'Siklus menu' => DB::table('menu_cycles')->whereIn('beneficiary_period_id', $periods()),
                'Kebutuhan bahan' => DB::table('nutrition_requirement_plans')->whereIn('beneficiary_period_id', $periods()),
                'Rencana distribusi' => DB::table('field_distribution_plans')->whereIn('beneficiary_period_id', $periods()),
                'Tujuan rencana distribusi' => DB::table('field_distribution_plan_destinations')
                    ->whereIn('daily_beneficiary_confirmation_id', $confirmations())
                    ->orWhereIn('beneficiary_period_destination_id', DB::table('beneficiary_period_destinations')->whereIn('beneficiary_period_id', $periods())->select('id')),
            ];
        }

        $lots = fn (): Builder => DB::table('inventory_lots')->where('sppg_unit_id', $unitId)->select('id');
        $withdrawals = fn (): Builder => DB::table('warehouse_withdrawals')->where('sppg_unit_id', $unitId)->select('id');
        $withdrawalItems = fn (): Builder => DB::table('warehouse_withdrawal_items')->whereIn('warehouse_withdrawal_id', $withdrawals())->select('id');

        return [
            'Penerimaan terkait Pengadaan' => DB::table('stock_receipts')->where('sppg_unit_id', $unitId)->whereNotNull('procurement_request_id'),
            'Pekerjaan Persiapan' => DB::table('preparation_sessions')->whereIn('warehouse_withdrawal_id', $withdrawals()),
            'Bahan Persiapan' => DB::table('preparation_session_items')->whereIn('inventory_lot_id', $lots()),
            'Bahan Pengolahan' => DB::table('processing_material_usages')->whereIn('inventory_lot_id', $lots())
                ->orWhere(fn (Builder $query) => $query->whereIn('source_type', ['warehouse', 'warehouse_withdrawal'])->whereIn('source_item_id', $withdrawalItems())),
            'Stok Pengolahan' => DB::table('processing_material_stocks')->whereIn('inventory_lot_id', $lots())
                ->orWhere(fn (Builder $query) => $query->where('source_type', 'warehouse')->whereIn('source_item_id', $withdrawalItems())),
            'Bahan Pemorsian' => DB::table('portioning_supplies')->whereIn('inventory_lot_id', $lots())
                ->orWhere(fn (Builder $query) => $query->where('source_type', 'warehouse_withdrawal')->whereIn('source_item_id', $withdrawalItems())),
            'Retur Persiapan' => DB::table('preparation_returns')->whereIn('source_inventory_lot_id', $lots())->orWhereIn('destination_inventory_lot_id', $lots()),
            'Retur Pengolahan' => DB::table('processing_returns')->whereIn('source_inventory_lot_id', $lots())->orWhereIn('destination_inventory_lot_id', $lots()),
        ];
    }
}
