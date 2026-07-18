<?php

namespace App\Services;

use App\Models\MenuAcceptanceEvaluation;

class MenuAcceptanceEvaluationService
{
    /**
     * @return array<int, string>
     */
    public function readinessIssues(MenuAcceptanceEvaluation $evaluation): array
    {
        $issues = [];

        if ((int) $evaluation->served_portions <= 0) {
            $issues[] = 'Jumlah porsi disajikan harus lebih dari nol.';
        }

        if ((int) $evaluation->accepted_portions > (int) $evaluation->served_portions) {
            $issues[] = 'Jumlah porsi diterima tidak boleh melebihi porsi disajikan.';
        }

        if ((int) $evaluation->leftover_portions > (int) $evaluation->served_portions) {
            $issues[] = 'Jumlah porsi tersisa tidak boleh melebihi porsi disajikan.';
        }

        if (
            (int) $evaluation->accepted_portions +
                (int) $evaluation->leftover_portions >
            (int) $evaluation->served_portions
        ) {
            $issues[] = 'Jumlah porsi diterima dan tersisa tidak boleh melebihi porsi disajikan.';
        }

        $scores = [
            'warna' => $evaluation->color_score,
            'aroma' => $evaluation->aroma_score,
            'rasa' => $evaluation->taste_score,
            'tekstur' => $evaluation->texture_score,
            'porsi' => $evaluation->portion_score,
            'suhu' => $evaluation->temperature_score,
        ];

        foreach ($scores as $label => $score) {
            if ($score === null || $score < 1 || $score > 5) {
                $issues[] = "Skor {$label} harus diisi antara 1 sampai 5.";
            }
        }

        if (blank($evaluation->photo_path)) {
            $issues[] = 'Foto evaluasi belum tersedia.';
        }

        $minimumAcceptance = (float) config(
            'nutritionist.minimum_acceptance_percent',
            80
        );
        $maximumWaste = (float) config(
            'nutritionist.maximum_waste_percent',
            20
        );

        if (
            $evaluation->acceptance_percent !== null &&
            (float) $evaluation->acceptance_percent < $minimumAcceptance &&
            blank($evaluation->corrective_actions)
        ) {
            $issues[] = 'Penerimaan menu di bawah batas dan belum memiliki tindakan koreksi.';
        }

        if (
            $evaluation->waste_percent !== null &&
            (float) $evaluation->waste_percent > $maximumWaste &&
            blank($evaluation->corrective_actions)
        ) {
            $issues[] = 'Sisa makanan melebihi batas dan belum memiliki tindakan koreksi.';
        }

        return array_values(array_unique($issues));
    }
}
