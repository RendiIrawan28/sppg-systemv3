<?php

namespace App\Support\V3;

final class MonitoringNavigation
{
    /** @return array<int, array<string, mixed>> */
    public function for(string $date, ?string $activeTab = null, bool $landing = false): array
    {
        return [
            $this->item('home', 'Beranda Monitoring', 'home', route('v3.monitoring.index', ['date' => $date]), $landing),
            $this->item('overview', 'Ikhtisar', 'check-badge', $this->detailUrl('overview', $date), ! $landing && $activeTab === 'overview'),
            $this->item('warehouse', 'Gudang', 'box', $this->detailUrl('warehouse', $date), ! $landing && $activeTab === 'warehouse'),
            $this->item('preparation', 'Persiapan', 'clipboard', $this->detailUrl('preparation', $date), ! $landing && $activeTab === 'preparation'),
            $this->item('processing', 'Pengolahan', 'nutrition', $this->detailUrl('processing', $date), ! $landing && $activeTab === 'processing'),
            $this->item('portioning', 'Pemorsian', 'calculator', $this->detailUrl('portioning', $date), ! $landing && $activeTab === 'portioning'),
            $this->item('distribution', 'Distribusi', 'truck', $this->detailUrl('distribution', $date), ! $landing && $activeTab === 'distribution'),
        ];
    }

    private function detailUrl(string $tab, string $date): string
    {
        return route('v3.monitoring.operational', compact('tab', 'date'));
    }

    /** @return array<string, mixed> */
    private function item(string $key, string $label, string $icon, string $url, bool $active): array
    {
        return compact('key', 'label', 'icon', 'url', 'active');
    }
}
