<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\CleaningSessionPdfController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\DistributionRunPdfController;
use App\Http\Controllers\FieldDailyReportPdfController;
use App\Http\Controllers\FieldDistributionPlanExcelController;
use App\Http\Controllers\FieldDistributionPlanPdfController;
use App\Http\Controllers\PortioningSessionPdfController;
use App\Http\Controllers\PreparationSessionCalculationPdfController;
use App\Http\Controllers\PreparationSessionWastePdfController;
use App\Http\Controllers\ProcessingBatchPdfController;
use App\Http\Controllers\WashingSessionPdfController;
use App\Http\Controllers\WashingSessionWastePdfController;
use App\Http\Controllers\WasteHandoverPdfController;
use App\Models\FieldDistributionPlan;
use App\Support\Mobile\MobileWorkspaceRegistry;
use App\Support\V3\SystemUnit;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MobileDocumentController extends Controller
{
    public function operational(
        Request $request,
        string $module,
        int $record,
        MobileWorkspaceRegistry $registry,
        SystemUnit $systemUnit,
    ): Response {
        $definition = $registry->authorize($request->user(), $module);
        $model = $definition['model'];
        $query = $model::query()->where('sppg_unit_id', $systemUnit->id());
        if ($module === 'distribusi' && ! $request->user()->can('distribution.approve')) {
            $query->where(function ($query) use ($request): void {
                $query->where('state', 'planned')
                    ->orWhere('petugas_id', $request->user()->getKey());
            });
        }
        $item = $query->findOrFail($record);
        $request->attributes->set('v3Unit', $systemUnit->get());

        return match ($module) {
            'lapangan-laporan' => app(FieldDailyReportPdfController::class)($item),
            'persiapan' => $request->query('type') === 'waste'
                ? app(PreparationSessionWastePdfController::class)($request, $item)
                : app(PreparationSessionCalculationPdfController::class)($request, $item),
            'pengolahan' => $request->query('type') === 'temperature'
                ? app(ProcessingBatchPdfController::class)->temperature($request, $item)
                : app(ProcessingBatchPdfController::class)->production($request, $item),
            'pemorsian' => app(PortioningSessionPdfController::class)($request, $item),
            'distribusi' => app(DistributionRunPdfController::class)($request, $item),
            'pencucian' => $request->query('type') === 'waste'
                ? app(WashingSessionWastePdfController::class)($request, $item)
                : app(WashingSessionPdfController::class)($request, $item),
            'kebersihan' => app(CleaningSessionPdfController::class)($request, $item),
            'ba-limbah-persiapan', 'ba-limbah-pencucian', 'ba-limbah-kebersihan' => app(WasteHandoverPdfController::class)($request, $item),
            default => abort(404, 'Dokumen PDF belum tersedia untuk modul ini.'),
        };
    }

    public function fieldPlan(
        Request $request,
        FieldDistributionPlan $fieldDistributionPlan,
        SystemUnit $systemUnit,
    ): Response {
        abort_unless((int) $fieldDistributionPlan->sppg_unit_id === (int) $systemUnit->id(), 404);

        return $request->query('format') === 'xlsx'
            ? app(FieldDistributionPlanExcelController::class)($request, $fieldDistributionPlan)
            : app(FieldDistributionPlanPdfController::class)($request, $fieldDistributionPlan);
    }
}
