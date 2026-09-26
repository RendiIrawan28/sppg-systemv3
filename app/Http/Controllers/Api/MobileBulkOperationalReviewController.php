<?php

namespace App\Http\Controllers\Api;

use App\Enums\FieldDailyReportStatus;
use App\Http\Controllers\Controller;
use App\Services\BulkOperationalReportReviewService;
use App\Services\CleaningWorkflow;
use App\Services\DistributionWorkflow;
use App\Services\FieldDailyReportWorkflow;
use App\Services\PortioningWorkflow;
use App\Services\PreparationSessionService;
use App\Services\ProcessingWorkflow;
use App\Services\WashingWorkflow;
use App\Services\WasteHandoverWorkflow;
use App\Support\Mobile\MobileWorkspaceRegistry;
use App\Support\V3\SystemUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileBulkOperationalReviewController extends Controller
{
    private const WORKFLOWS = [
        'persiapan' => PreparationSessionService::class,
        'pengolahan' => ProcessingWorkflow::class,
        'pemorsian' => PortioningWorkflow::class,
        'distribusi' => DistributionWorkflow::class,
        'pencucian' => WashingWorkflow::class,
        'kebersihan' => CleaningWorkflow::class,
        'lapangan-laporan' => FieldDailyReportWorkflow::class,
        'ba-limbah-pencucian' => WasteHandoverWorkflow::class,
        'ba-limbah-kebersihan' => WasteHandoverWorkflow::class,
    ];

    public function __invoke(
        Request $request,
        string $module,
        MobileWorkspaceRegistry $registry,
        SystemUnit $systemUnit,
        BulkOperationalReportReviewService $bulk,
    ): JsonResponse {
        abort_unless(isset(self::WORKFLOWS[$module]), 404);
        $definition = $registry->authorize($request->user(), $module);
        $date = $request->validate(['date' => ['required', 'date_format:Y-m-d']])['date'];
        $workflow = app(self::WORKFLOWS[$module]);
        $method = in_array($module, ['persiapan', 'lapangan-laporan'], true) ? 'approve' : 'verify';
        $count = $bulk->review(
            $this->scope($module, $definition, (int) $systemUnit->id(), $date, (int) $request->user()->getKey()),
            $request->user(), $definition['permission'].'.approve',
            fn ($record, $actor) => $workflow->{$method}($record, $actor),
            $module === 'lapangan-laporan' ? FieldDailyReportStatus::Submitted->value : null,
        );

        return response()->json([
            'message' => "{$count} laporan {$definition['label']} berhasil disetujui pada tahap ini.",
            'reviewed_count' => $count,
        ]);
    }

    /** @param array<string, mixed> $definition
     * @return array{date: string, count: int}|null
     */
    public function capability(Request $request, string $module, array $definition, int $unitId, string $date): ?array
    {
        if (! isset(self::WORKFLOWS[$module]) || ! $request->user()->can($definition['permission'].'.approve')) {
            return null;
        }

        return [
            'date' => $date,
            'count' => app(BulkOperationalReportReviewService::class)->pendingCount(
                $this->scope($module, $definition, $unitId, $date, (int) $request->user()->getKey()),
                $request->user(), $definition['permission'].'.approve',
                $module === 'lapangan-laporan' ? FieldDailyReportStatus::Submitted->value : null,
            ),
        ];
    }

    /** @param array<string, mixed> $definition */
    private function scope(string $module, array $definition, int $unitId, string $date, int $actorId): Builder
    {
        $model = $definition['model'];
        $query = $model::query()->where('sppg_unit_id', $unitId)
            ->whereDate($definition['date'], $date);

        if (str_starts_with($module, 'ba-limbah-')) {
            $query->where('division_type', $definition['where']['division_type'])
                ->where(fn (Builder $scope) => $scope->whereNull('source_type')->orWhere('source_type', '!=', 'preparation_session'));
        }

        if ($module === 'lapangan-laporan') {
            $query->where('submitted_by', '!=', $actorId);
        }

        return $query;
    }
}
