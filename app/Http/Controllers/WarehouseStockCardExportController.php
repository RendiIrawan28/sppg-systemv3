<?php

namespace App\Http\Controllers;

use App\Models\Warehouse;
use App\Services\WarehouseStockCardService;
use App\Support\V3\OperationsPresentation;
use App\Support\V3\SystemUnit;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WarehouseStockCardExportController extends Controller
{
    public function __invoke(Request $request, SystemUnit $systemUnit, WarehouseStockCardService $cards): StreamedResponse
    {
        abort_unless($request->user()->is_super_admin || $request->user()->can('stock.view'), 403);
        $filters = $request->validate(['bahan' => ['nullable', 'integer', 'min:1'], 'q' => ['nullable', 'string', 'max:100']]);
        $warehouse = Warehouse::query()->where('sppg_unit_id', $systemUnit->id())->where('type', Warehouse::TYPE_FOOD)->where('is_active', true)->firstOrFail();
        $ingredientId = isset($filters['bahan']) ? (int) $filters['bahan'] : null;
        $balances = $cards->cards($systemUnit->id(), $warehouse->id, $filters['q'] ?? '', ingredientId: $ingredientId);
        if ($ingredientId !== null) {
            abort_if($balances->isEmpty(), 404);
        }
        $ledger = $ingredientId !== null ? $cards->ledger($systemUnit->id(), $warehouse->id, $ingredientId) : collect();

        return response()->streamDownload(function () use ($balances, $ledger, $ingredientId): void {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            $write = function (array $row) use ($file): void {
                fputcsv($file, array_map(static fn ($value) => is_string($value) && preg_match('/^[\s]*[=+@-]/u', $value) ? "'".$value : $value, $row), ';', '"', '');
            };
            if ($ingredientId === null) {
                $write(['Kode', 'Nama', 'Satuan', 'Saldo', 'Lot Aktif', 'Catatan']);
                foreach ($balances as $card) {
                    $write([$card->code, $card->ingredient_name_snapshot, $card->unit_snapshot, $card->balance_quantity, $card->active_lot_count, $card->conversion_warning]);
                }
            } else {
                $card = $balances->first();
                $write(['Kode', $card->code, 'Nama', $card->ingredient_name_snapshot, 'Satuan', $card->unit_snapshot]);
                $write(['Tanggal', 'Referensi', 'Jenis', 'Masuk', 'Keluar', 'Saldo', 'Lot internal', 'Lot supplier', 'Catatan']);
                foreach ($ledger as $movement) {
                    $write([$movement->movement_date?->format('Y-m-d'), $movement->reference_number, OperationsPresentation::movementTypes()[$movement->movement_type] ?? $movement->movement_type, $movement->card_quantity_in, $movement->card_quantity_out, $movement->running_balance, $movement->inventory_lot_id, $movement->supplier_batch_number, $movement->notes]);
                }
            }
            fclose($file);
        }, $ingredientId ? 'mutasi-kartu-stok.csv' : 'rekap-kartu-stok.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
