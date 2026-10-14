<?php

namespace App\Http\Controllers;

use App\Models\PortioningReturn;
use App\Models\PreparationReturn;
use App\Models\ProcessingReturn;
use App\Models\StockReceipt;
use App\Models\Warehouse;
use App\Models\WarehouseWithdrawal;
use App\Support\FileNaming;
use App\Support\V3\SystemUnit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WarehouseDailyReportController extends Controller
{
    public function __invoke(Request $request, string $report, SystemUnit $systemUnit): Response
    {
        abort_unless($request->user()?->can('stock.view'), 403);
        abort_unless(in_array($report, ['receipts', 'withdrawals-returns'], true), 404);

        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $date = $filters['date'] ?? today()->toDateString();
        $unit = $systemUnit->get();
        abort_unless($unit, 404);

        if ($report === 'receipts') {
            $receipts = StockReceipt::query()
                ->with(['supplier', 'procurementRequest', 'items.ingredient'])
                ->where('sppg_unit_id', $unit->getKey())
                ->whereDate('receipt_date', $date)
                ->where(fn ($query) => $query->whereHas('warehouse', fn ($warehouse) => $warehouse
                    ->where('type', Warehouse::TYPE_FOOD))->orWhereNull('warehouse_id'))
                ->orderBy('receipt_number')
                ->get();

            return Pdf::loadView('reports.warehouse-receipts-daily-pdf', compact('unit', 'date', 'receipts'))
                ->setPaper('a4', 'landscape')
                ->download(FileNaming::report('laporan-penerimaan-bahan-baku', null, $date, 'pdf'));
        }

        $withdrawals = WarehouseWithdrawal::query()
            ->with(['items.ingredient', 'taker', 'verifier'])
            ->where('sppg_unit_id', $unit->getKey())
            ->whereDate('withdrawal_date', $date)
            ->where(fn ($query) => $query->whereHas('warehouse', fn ($warehouse) => $warehouse
                ->where('type', Warehouse::TYPE_FOOD))->orWhereNull('warehouse_id'))
            ->orderBy('withdrawal_number')
            ->get();

        $returns = collect([
            'Persiapan' => PreparationReturn::class,
            'Pengolahan' => ProcessingReturn::class,
            'Pemorsian' => PortioningReturn::class,
        ])->flatMap(fn (string $model, string $division) => $model::query()
            ->with(['returner', 'verifier'])
            ->where('sppg_unit_id', $unit->getKey())
            ->whereDate('return_date', $date)
            ->orderBy('return_number')
            ->get()
            ->map(fn ($item) => ['division' => $division, 'record' => $item]));

        return Pdf::loadView('reports.warehouse-withdrawals-returns-daily-pdf', compact('unit', 'date', 'withdrawals', 'returns'))
            ->setPaper('a4', 'landscape')
            ->download(FileNaming::report('laporan-pengambilan-retur-bahan-baku', null, $date, 'pdf'));
    }
}
