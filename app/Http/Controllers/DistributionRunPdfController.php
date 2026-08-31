<?php

namespace App\Http\Controllers;

use App\Models\DistributionRun;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class DistributionRunPdfController extends Controller
{
    public function __invoke(Request $request, DistributionRun $distributionRun): Response
    {
        $this->authorizeSystemRecord($distributionRun, 'distribution.export');

        $relations = [
            'sppgUnit',
            'fieldDistributionPlan',
            'portioningSession',
            'stops',
            'documentations',
            'incidents.stop',
            'petugas',
            'verifier',
        ];

        $runs = $this->runsForExport($distributionRun, $relations);
        $primaryRun = $runs->first() ?? $distributionRun->load($relations);
        $plan = $primaryRun->fieldDistributionPlan;

        $pdf = Pdf::loadView('reports.distribution-run-pdf', [
            'run' => $primaryRun,
            'runs' => $runs,
            'plan' => $plan,
        ])->setPaper('a4', 'landscape');

        $reference = $plan?->plan_number ?: $primaryRun->run_number;

        return $pdf->download(
            'LAPORAN-DISTRIBUSI-SELURUH-RUTE-'.str_replace('/', '-', $reference).'.pdf'
        );
    }

    /**
     * @param  array<int, string>  $relations
     * @return Collection<int, DistributionRun>
     */
    private function runsForExport(DistributionRun $run, array $relations): Collection
    {
        if (! $run->field_distribution_plan_id) {
            return collect([$run->load($relations)]);
        }

        return DistributionRun::query()
            ->where('sppg_unit_id', $run->sppg_unit_id)
            ->where('field_distribution_plan_id', $run->field_distribution_plan_id)
            ->with($relations)
            ->orderBy('route_name')
            ->orderBy('id')
            ->get();
    }
}
