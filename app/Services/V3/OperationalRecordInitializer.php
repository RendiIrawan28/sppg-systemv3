<?php

namespace App\Services\V3;

use App\Models\CleaningArea;
use App\Models\CleaningSession;
use App\Models\ContainerCollectionRun;
use App\Models\DistributionRun;
use App\Models\PortioningSession;
use App\Models\User;
use App\Models\WashingSession;
use App\Support\CleaningChecklistTemplate;
use Illuminate\Support\Facades\Schema;

final class OperationalRecordInitializer
{
    public function initialize(object $record, User $actor): void
    {
        match (true) {
            $record instanceof DistributionRun => $this->distribution($record),
            $record instanceof WashingSession => $this->washing($record),
            $record instanceof CleaningSession => $this->cleaning($record),
            default => null,
        };
    }

    private function distribution(DistributionRun $run): void
    {
        if (! $run->portioning_session_id || $run->stops()->exists()) {
            return;
        }

        $session = PortioningSession::query()->with('routeAllocations')->find($run->portioning_session_id);
        foreach ($session?->routeAllocations ?? [] as $index => $route) {
            $small = (int) $route->target_small_portions;
            $large = (int) $route->target_large_portions;
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
                'containers_sent' => 0,
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
        if ($session->container_collection_run_id) {
            $run = ContainerCollectionRun::query()->with(['items.task'])->find($session->container_collection_run_id);
            if ($run) {
                $collected = (int) $run->items->sum('collected_quantity');
                $target = $collected;

                $session->updateQuietly([
                    'menu_name_snapshot' => $session->menu_name_snapshot ?: 'Pengambilan ompreng '.$run->run_number,
                    'distribution_expected_containers' => $session->distribution_expected_containers ?: $target,
                    'distribution_returned_containers' => $session->distribution_returned_containers ?: $collected,
                    'distribution_damaged_containers' => 0,
                    'distribution_lost_containers' => 0,
                    'expected_containers' => $session->expected_containers ?: $collected,
                ]);
            }
        } elseif ($session->distribution_run_id) {
            $run = DistributionRun::query()->with('stops')->find($session->distribution_run_id);
            if ($run) {
                $sent = (int) $run->stops->sum('containers_sent');
                $returned = (int) $run->containers_returned;
                $damaged = (int) $run->containers_damaged;
                $lost = (int) $run->containers_lost;

                // Fallback untuk perjalanan lama sebelum rekonsiliasi ompreng dipindah ke tingkat rute.
                if (($returned + $damaged + $lost) <= 0) {
                    $returned = (int) $run->stops->sum('containers_returned');
                    $damaged = (int) $run->stops->sum('containers_damaged');
                    $lost = (int) $run->stops->sum('containers_lost');
                }

                $physicalExpected = $returned + $damaged;

                $session->updateQuietly([
                    'menu_name_snapshot' => $session->menu_name_snapshot ?: $run->menu_name_snapshot,
                    'distribution_expected_containers' => $session->distribution_expected_containers ?: $sent,
                    'distribution_returned_containers' => $session->distribution_returned_containers ?: $returned,
                    'distribution_damaged_containers' => $session->distribution_damaged_containers ?: $damaged,
                    'distribution_lost_containers' => $session->distribution_lost_containers ?: $lost,
                    'expected_containers' => $session->expected_containers ?: $physicalExpected,
                ]);
            }
        }

        if ($session->checklistItems()->exists()) {
            return;
        }
        $items = [
            ['pre_rinse', 'Sisa makanan sudah dipisahkan dan dibuang sebelum pencucian'],
            ['main_wash', 'Ompreng sudah dicuci dan dibilas hingga bebas residu'],
            ['drying', 'Ompreng sudah dikeringkan tanpa kontaminasi ulang'],
            ['final_inspection', 'Ompreng bersih, tidak berbau, dan layak digunakan'],
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
        $templateItems = $area
            ? CleaningChecklistTemplate::items(CleaningChecklistTemplate::forArea($area))
            : [];

        $items = $templateItems !== []
            ? $templateItems
            : (is_array($area?->default_checklist) && $area->default_checklist !== []
                ? $area->default_checklist
                : [
                    ['category' => 'preparation', 'item_name' => 'Sampah dan benda tidak diperlukan telah dipindahkan'],
                    ['category' => 'surface', 'item_name' => 'Meja, dinding, dan permukaan kontak telah dibersihkan'],
                    ['category' => 'floor', 'item_name' => 'Lantai bersih, tidak licin, dan tidak ada genangan'],
                    ['category' => 'drain', 'item_name' => 'Saluran air bersih dan tidak tersumbat'],
                    ['category' => 'equipment', 'item_name' => 'Peralatan kebersihan dicuci dan disimpan pada tempatnya'],
                    ['category' => 'final', 'item_name' => 'Area tidak berbau, bebas hama, dan siap digunakan'],
                ]);

        foreach (array_values($items) as $index => $item) {
            $normalized = is_string($item)
                ? ['category' => 'other', 'item_name' => $item, 'is_mandatory' => true]
                : (array) $item;

            $session->checklistItems()->create([
                'category' => $normalized['category'] ?? 'other',
                'item_name' => $normalized['item_name'] ?? 'Pemeriksaan area',
                'is_mandatory' => (bool) ($normalized['is_mandatory'] ?? true),
                'result' => 'pending',
                'sort_order' => $index + 1,
            ]);
        }
    }
}
