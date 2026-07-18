<?php

namespace App\Http\Controllers;

use App\Models\FieldDistributionPlan;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FieldDistributionPlanPdfController extends Controller
{
    public function __invoke(Request $request, FieldDistributionPlan $fieldDistributionPlan): Response
    {
        $this->authorizeExport($request, $fieldDistributionPlan);

        $fieldDistributionPlan->loadMissing([
            'sppgUnit',
            'destinations.recipientGroups',
            'creator',
            'approver',
        ]);

        $filename = sprintf(
            'rencana-distribusi-%s.pdf',
            str_replace(['/', '\\'], '-', (string) $fieldDistributionPlan->plan_number)
        );

        return Pdf::loadView('reports.field-distribution-plan-pdf', [
            'plan' => $fieldDistributionPlan,
        ])
            ->setPaper('a4', 'landscape')
            ->download($filename);
    }

    private function authorizeExport(Request $request, FieldDistributionPlan $fieldDistributionPlan): void
    {
        $this->authorizeSystemRecord($fieldDistributionPlan, 'field_planning.export');
    }
}
