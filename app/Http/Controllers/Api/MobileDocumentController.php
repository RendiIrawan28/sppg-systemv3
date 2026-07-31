<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\CleaningSessionPdfController;
use App\Http\Controllers\DistributionRunPdfController;
use App\Http\Controllers\FieldDailyReportPdfController;
use App\Http\Controllers\FieldDistributionPlanPdfController;
use App\Http\Controllers\PortioningSessionPdfController;
use App\Http\Controllers\ProcessingBatchPdfController;
use App\Http\Controllers\WashingSessionPdfController;
use App\Http\Controllers\WasteHandoverPdfController;
use App\Http\Controllers\Controller;
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
        $item = $model::query()
            ->where('sppg_unit_id', $systemUnit->id())
            ->findOrFail($record);

        return match ($module) {
            'lapangan-laporan' => app(FieldDailyReportPdfController::class)($item),
            'pengolahan' => app(ProcessingBatchPdfController::class)->production($request, $item),
            'pemorsian' => app(PortioningSessionPdfController::class)($request, $item),
            'distribusi' => app(DistributionRunPdfController::class)($request, $item),
            'pencucian' => app(WashingSessionPdfController::class)($request, $item),
            'kebersihan' => app(CleaningSessionPdfController::class)($request, $item),
            'ba-limbah-persiapan', 'ba-limbah-pencucian', 'ba-limbah-kebersihan' =>
                app(WasteHandoverPdfController::class)($request, $item),
            default => abort(404, 'Dokumen PDF belum tersedia untuk modul ini.'),
        };
    }

    public function fieldPlan(
        Request $request,
        FieldDistributionPlan $fieldDistributionPlan,
        SystemUnit $systemUnit,
    ): Response {
        abort_unless((int) $fieldDistributionPlan->sppg_unit_id === (int) $systemUnit->id(), 404);

        return app(FieldDistributionPlanPdfController::class)($request, $fieldDistributionPlan);
    }
}
