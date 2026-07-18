<?php

namespace App\Services\V3;

use App\Models\Beneficiary;
use App\Models\CleaningSession;
use App\Models\DistributionRun;
use App\Models\FieldDistributionPlan;
use App\Models\FieldIncident;
use App\Models\Menu;
use App\Models\PortioningSession;
use App\Models\ProcessingBatch;
use App\Models\ProcurementRequest;
use App\Models\SppgUnit;
use App\Models\StockReceipt;
use App\Models\User;
use App\Models\WashingSession;
use App\Support\V3\Navigation;

final class DashboardSummary
{
    /** @return array<string, mixed> */
    public function for(User $user, SppgUnit $unit): array
    {
        $unitId = (int) $unit->getKey();
        $today = now()->toDateString();
        $cards = [];

        if ($this->allowed($user, 'beneficiaries.view')) {
            $cards[] = $this->card(
                'Penerima aktif',
                Beneficiary::query()->where('sppg_unit_id', $unitId)->where('is_active', true)->count(),
                'Data master unit saat ini',
                'users',
                'sky',
                route('v3.beneficiaries.index'),
            );
        }

        if ($this->allowed($user, 'menus.view')) {
            $menu = Menu::query()
                ->where('sppg_unit_id', $unitId)
                ->whereDate('service_date', $today)
                ->orderByDesc('id')
                ->first();

            $cards[] = $this->card(
                'Menu hari ini',
                $menu?->planned_portions ?? 0,
                $menu?->name ?? 'Belum ada menu terjadwal',
                'clipboard',
                $menu ? 'emerald' : 'slate',
            );
        }

        if ($this->allowed($user, 'procurement.view')) {
            $cards[] = $this->card(
                'Pengadaan berjalan',
                ProcurementRequest::query()
                    ->where('sppg_unit_id', $unitId)
                    ->whereNotIn('status', [ProcurementRequest::STATUS_ORDERED])
                    ->count(),
                'Belum selesai dipesan gudang',
                'cart',
                'amber',
            );
        }

        if ($this->allowed($user, 'field_incidents.view') || $this->allowed($user, 'incidents.view')) {
            $cards[] = $this->card(
                'Insiden terbuka',
                FieldIncident::query()->where('sppg_unit_id', $unitId)->open()->count(),
                'Memerlukan tindak lanjut',
                'alert',
                'rose',
            );
        }

        if ($this->allowed($user, 'stock.view')) {
            $cards[] = $this->card(
                'Penerimaan hari ini',
                StockReceipt::query()
                    ->where('sppg_unit_id', $unitId)
                    ->whereDate('receipt_date', $today)
                    ->count(),
                'Dokumen barang masuk',
                'box',
                'violet',
            );
        }

        return [
            'cards' => $cards,
            'pulse' => $this->operationalPulse($user, $unitId, $today),
            'roadmap' => app(Navigation::class)->roadmap($user),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function operationalPulse(User $user, int $unitId, string $today): array
    {
        $definitions = [
            ['permission' => 'field_planning.view', 'label' => 'Rencana', 'model' => FieldDistributionPlan::class, 'date' => 'distribution_date'],
            ['permission' => 'processing.view', 'label' => 'Pengolahan', 'model' => ProcessingBatch::class, 'date' => 'production_date'],
            ['permission' => 'portioning.view', 'label' => 'Pemorsian', 'model' => PortioningSession::class, 'date' => 'portioning_date'],
            ['permission' => 'distribution.view', 'label' => 'Distribusi', 'model' => DistributionRun::class, 'date' => 'distribution_date'],
            ['permission' => 'washing.view', 'label' => 'Pencucian', 'model' => WashingSession::class, 'date' => 'washing_date'],
            ['permission' => 'cleaning.view', 'label' => 'Kebersihan', 'model' => CleaningSession::class, 'date' => 'scheduled_date'],
        ];

        return collect($definitions)
            ->filter(fn (array $item): bool => $this->allowed($user, $item['permission']))
            ->map(function (array $item) use ($unitId, $today): array {
                $count = $item['model']::query()
                    ->where('sppg_unit_id', $unitId)
                    ->whereDate($item['date'], $today)
                    ->count();

                return [
                    'label' => $item['label'],
                    'count' => $count,
                    'state' => $count > 0 ? 'ready' : 'empty',
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function card(
        string $label,
        int|float|string $value,
        string $detail,
        string $icon,
        string $tone,
        ?string $url = null,
    ): array {
        return compact('label', 'value', 'detail', 'icon', 'tone', 'url');
    }

    private function allowed(User $user, string $permission): bool
    {
        return $user->is_super_admin || $user->can($permission);
    }
}
