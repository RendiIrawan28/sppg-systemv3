<?php

namespace App\Services\V3;

use App\Models\CleaningArea;
use App\Models\CleaningSession;
use App\Models\DistributionRun;
use App\Models\FieldDistributionPlan;
use App\Models\PortioningSession;
use App\Models\ProcessingBatch;
use App\Models\User;
use App\Models\WashingSession;
use Illuminate\Support\Facades\Schema;

final class OperationalRecordInitializer
{
    public function initialize(object $record, User $actor): void
    {
        match (true) {
            $record instanceof ProcessingBatch => $this->processing($record, $actor),
            $record instanceof PortioningSession => $this->portioning($record, $actor),
            $record instanceof DistributionRun => $this->distribution($record),
            $record instanceof WashingSession => $this->washing($record),
            $record instanceof CleaningSession => $this->cleaning($record),
            default => null,
        };
    }

    private function processing(ProcessingBatch $batch, User $actor): void
    {
        $batch->forceFill([
            'petugas_name_snapshot' => $batch->petugas?->name ?: $actor->name,
        ])->saveQuietly();
    }

    private function portioning(PortioningSession $session, User $actor): void
    {
        $session->forceFill([
            'petugas_name_snapshot' => $session->petugas?->name ?: $actor->name,
        ])->saveQuietly();

        if (! $session->processing_batch_id || $session->routeAllocations()->exists()) {
            return;
        }

        $batch = ProcessingBatch::query()->find($session->processing_batch_id);
        $plan = $batch?->field_distribution_plan_id
            ? FieldDistributionPlan::query()->with('destinations')->find($batch->field_distribution_plan_id)
            : null;

        foreach ($plan?->destinations ?? [] as $destination) {
            $allocation = $session->routeAllocations()->make([
                'route_name' => $destination->route_name ?: $destination->destination_name_snapshot,
                'destination_name' => $destination->destination_name_snapshot,
                'destination_type' => $destination->destination_type,
                'address' => $destination->address_snapshot,
                'contact_name' => $destination->contact_name_snapshot,
                'contact_phone' => $destination->contact_phone_snapshot,
                'planned_arrival_at' => $destination->planned_arrival_at,
                'planned_departure_at' => $destination->planned_departure_at,
                'latitude' => $destination->latitude_snapshot,
                'longitude' => $destination->longitude_snapshot,
                'target_small_portions' => (int) $destination->small_portions,
                'target_large_portions' => (int) $destination->large_portions,
                'actual_small_portions' => 0,
                'actual_large_portions' => 0,
                'sort_order' => (int) $destination->sequence_order,
                'notes' => "Tujuan: {$destination->destination_name_snapshot}",
            ]);
            if (Schema::hasColumn('portioning_route_allocations', 'field_distribution_plan_destination_id')) {
                $allocation->field_distribution_plan_destination_id = $destination->getKey();
            }
            $allocation->save();
        }

        $session->recalculateTotals();
    }

    private function distribution(DistributionRun $run): void
    {
        if (! $run->portioning_session_id || $run->stops()->exists()) {
            return;
        }

        $session = PortioningSession::query()->with('routeAllocations')->find($run->portioning_session_id);
        foreach ($session?->routeAllocations ?? [] as $index => $route) {
            $small = (int) $route->actual_small_portions > 0 ? (int) $route->actual_small_portions : (int) $route->target_small_portions;
            $large = (int) $route->actual_large_portions > 0 ? (int) $route->actual_large_portions : (int) $route->target_large_portions;
            $stop = $run->stops()->make([
                'route_name' => $route->route_name,
                'destination_name' => $route->destination_name ?: $route->route_name,
                'destination_type' => $route->destination_type,
                'address' => $route->address,
                'contact_name' => $route->contact_name,
                'contact_phone' => $route->contact_phone,
                'sequence_order' => (int) ($route->sort_order ?: $index + 1),
                'planned_arrival_at' => $route->planned_arrival_at,
                'small_portions' => $small,
                'large_portions' => $large,
                'delivered_small_portions' => 0,
                'delivered_large_portions' => 0,
                'returned_small_portions' => 0,
                'returned_large_portions' => 0,
                'containers_sent' => $small + $large,
                'latitude' => $route->latitude,
                'longitude' => $route->longitude,
                'notes' => $route->notes,
            ]);
            if (Schema::hasColumn('distribution_stops', 'field_distribution_plan_destination_id') && $route->field_distribution_plan_destination_id) {
                $stop->field_distribution_plan_destination_id = $route->field_distribution_plan_destination_id;
            }
            $stop->save();
        }
        $run->recalculateTotals();
    }

    private function washing(WashingSession $session): void
    {
        if ($session->distribution_run_id) {
            $run = DistributionRun::query()->with('stops')->find($session->distribution_run_id);
            if ($run) {
                $sent = (int) $run->stops->sum('containers_sent');
                $damaged = (int) $run->stops->sum('containers_damaged');
                $lost = (int) $run->stops->sum('containers_lost');
                $session->updateQuietly([
                    'menu_name_snapshot' => $session->menu_name_snapshot ?: $run->menu_name_snapshot,
                    'expected_containers' => $session->expected_containers ?: $sent,
                    'received_containers' => $session->received_containers ?: (int) $run->stops->sum('containers_returned') + $damaged,
                    'damaged_containers' => $session->damaged_containers ?: $damaged,
                    'missing_containers' => $session->missing_containers ?: $lost,
                ]);
            }
        }

        if ($session->checklistItems()->exists()) {
            return;
        }
        $items = [
            ['receiving', 'Jumlah dan kondisi ompreng dicocokkan saat penerimaan'],
            ['pre_rinse', 'Sisa makanan dibuang sebelum pencucian utama'],
            ['main_wash', 'Seluruh permukaan ompreng dicuci secara menyeluruh'],
            ['rinse', 'Ompreng bebas residu deterjen setelah pembilasan'],
            ['sanitation', 'Proses sanitasi dilakukan sesuai SOP Unit SPPG'],
            ['drying', 'Ompreng dikeringkan tanpa kontaminasi ulang'],
            ['final_inspection', 'Ompreng tidak bernoda, tidak berbau, dan layak digunakan'],
        ];
        foreach ($items as $index => [$category, $name]) {
            $session->checklistItems()->create([
                'category' => $category, 'item_name' => $name, 'is_mandatory' => true,
                'is_passed' => false, 'sort_order' => $index + 1,
            ]);
        }
    }

    private function cleaning(CleaningSession $session): void
    {
        if ($session->checklistItems()->exists()) {
            return;
        }
        $area = CleaningArea::query()->find($session->cleaning_area_id);
        $items = is_array($area?->default_checklist) && $area->default_checklist !== []
            ? $area->default_checklist
            : [
                ['category' => 'preparation', 'item_name' => 'Sampah dan benda tidak diperlukan telah dipindahkan'],
                ['category' => 'surface', 'item_name' => 'Meja, dinding, dan permukaan kontak telah dibersihkan'],
                ['category' => 'floor', 'item_name' => 'Lantai bersih, tidak licin, dan tidak ada genangan'],
                ['category' => 'drain', 'item_name' => 'Saluran air bersih dan tidak tersumbat'],
                ['category' => 'equipment', 'item_name' => 'Peralatan kebersihan dicuci dan disimpan pada tempatnya'],
                ['category' => 'final', 'item_name' => 'Area tidak berbau, bebas hama, dan siap digunakan'],
            ];
        foreach (array_values($items) as $index => $item) {
            $session->checklistItems()->create([
                'category' => $item['category'] ?? 'other', 'item_name' => $item['item_name'] ?? 'Pemeriksaan area',
                'is_mandatory' => (bool) ($item['is_mandatory'] ?? true), 'result' => 'pending', 'sort_order' => $index + 1,
            ]);
        }
    }
}
