<?php

namespace App\Http\Controllers;

use App\Models\FieldDistributionPlan;
use App\Support\FileNaming;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FieldDistributionPlanPdfController extends Controller
{
    public function __invoke(
        Request $request,
        FieldDistributionPlan $fieldDistributionPlan
    ): Response {
        $this->authorizeExport(
            $request,
            $fieldDistributionPlan
        );

        $fieldDistributionPlan->loadMissing([
            'sppgUnit',
            'destinations.recipientGroups',
            'creator',
            'approver',
        ]);

        $filename = FileNaming::report('rencana-distribusi', null, $fieldDistributionPlan->distribution_date, 'pdf');

        return Pdf::loadView(
            'reports.field-distribution-plan-pdf',
            [
                'plan' => $fieldDistributionPlan,
            ]
        )
            ->setPaper('a4', 'landscape')
            ->download($filename);
    }

    private function authorizeExport(
        Request $request,
        FieldDistributionPlan $fieldDistributionPlan
    ): void {
        $this->authorizeSystemRecord(
            $fieldDistributionPlan,
            'field_planning.export'
        );
    }
}
