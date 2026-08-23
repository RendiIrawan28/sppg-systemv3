<?php

namespace App\Services;

use App\Enums\OperationalReportStatus;
use App\Enums\WasteDivision;
use App\Models\PreparationSession;
use App\Models\User;
use App\Models\WasteHandoverReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PreparationWasteReportSyncService
{
    public function sync(PreparationSession $session, User $actor): ?WasteHandoverReport
    {
        return DB::transaction(function () use ($session, $actor): ?WasteHandoverReport {
            $session = PreparationSession::query()
                ->with(['items.resultDocumentation', 'wasteHandoverReport'])
                ->lockForUpdate()->findOrFail($session->getKey());
            $wasteItems = $session->items->filter(fn ($item): bool => (float) $item->waste_quantity > 0)->values();
            $report = $session->wasteHandoverReport;

            if ($wasteItems->isEmpty()) {
                if ($report && $report->status === OperationalReportStatus::Draft) {
                    $report->items()->delete();
                    $report->delete();
                    $session->update(['waste_handover_report_id' => null]);
                }
                return null;
            }

            if ($report && $report->status !== OperationalReportStatus::Draft) {
                throw ValidationException::withMessages([
                    'waste' => 'Berita Acara Limbah sudah dikunci dan data limbah tidak dapat diubah.',
                ]);
            }

            $report ??= WasteHandoverReport::create([
                'sppg_unit_id' => $session->sppg_unit_id,
                'division_type' => WasteDivision::Preparation,
                'source_type' => 'preparation_session',
                'source_id' => $session->getKey(),
                'source_reference' => $session->session_number,
                'report_date' => $session->preparation_date,
                'effective_date' => $session->preparation_date,
                'petugas_id' => $actor->getKey(),
                'petugas_name_snapshot' => $actor->name,
                'status' => OperationalReportStatus::Draft,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
                'notes' => 'Dibuat otomatis dari hasil Persiapan.',
            ]);

            $kept = [];
            foreach ($wasteItems as $index => $item) {
                $reportItem = $report->items()->updateOrCreate(
                    ['sort_order' => $index + 1],
                    [
                        'waste_type' => $item->ingredient_name_snapshot,
                        'quantity' => $item->waste_quantity,
                        'unit' => $item->unit_snapshot,
                        'weight_kg' => $item->unit_snapshot === 'kg' ? $item->waste_quantity : null,
                        'notes' => $item->notes,
                        'photo_path' => $item->resultDocumentation?->photo_path,
                    ],
                );
                $kept[] = $reportItem->getKey();
            }
            $report->items()->whereNotIn('id', $kept)->delete();
            $report->update(['updated_by' => $actor->getKey()]);
            if ((int) $session->waste_handover_report_id !== (int) $report->getKey()) {
                $session->update(['waste_handover_report_id' => $report->getKey()]);
            }

            return $report->refresh()->load('items');
        });
    }
}
