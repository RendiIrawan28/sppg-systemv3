<?php

namespace App\Http\Controllers;

use App\Models\PreparationMaterialHandover;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreparationMaterialCalculationPdfController extends Controller
{
    public function __invoke(Request $request, PreparationMaterialHandover $handover): Response
    {
        $this->authorizeSystemRecord($handover, 'preparation.export');

        $handover->load(['sppgUnit', 'items']);

        return Pdf::loadView('reports.preparation-material-calculation-pdf', [
            'handover' => $handover,
        ])
            ->setPaper('a4', 'portrait')
            ->download('BA-Perhitungan-Persiapan-'.str_replace('/', '-', $handover->handover_number).'.pdf');
    }
}
