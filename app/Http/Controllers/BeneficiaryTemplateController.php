<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BeneficiaryTemplateController extends Controller
{
    public function __invoke(
        Request $request,
        string $type
    ): BinaryFileResponse {
        $this->authorizeSystemUnit('beneficiaries.import');

        $filename = match ($type) {
            'school' => 
                'template_import_penerima_sekolah.xlsx',

            'posyandu' =>
                'template_import_penerima_posyandu.xlsx',

            default => abort(404),
        };

        $path = storage_path(
            'app/templates/' . $filename
        );

        abort_unless(
            file_exists($path),
            404,
            'File template tidak ditemukan.'
        );

        return response()->download(
            $path,
            $filename,
            [
                'Content-Type' =>
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]
        );
    }
}
